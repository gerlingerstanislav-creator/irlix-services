from __future__ import annotations

import json
import os
import re
import time
import uuid
from dataclasses import dataclass

import httpx

from .schema import CanonicalCv


SYSTEM_PROMPT = """Ты парсер профессиональных CV. Верни только JSON, соответствующий переданной схеме.
Правила:
- не выдумывай и не улучшай факты;
- сохраняй язык и смысл исходного CV;
- если данных нет, используй пустую строку, null или пустой список;
- опыт по проектам не смешивай: один проект/место работы = один объект projects;
- обязанности сохраняй отдельными пунктами, не сокращай важные детали;
- инструменты, технологии, языки и образование извлекай отдельно;
- персональные контакты извлекай только если они явно присутствуют.
"""


@dataclass
class ProviderResult:
    cv: CanonicalCv
    provider: str
    model: str
    inference_ms: int


def _json_from_text(text: str) -> dict:
    cleaned = text.strip()
    if cleaned.startswith("```"):
        cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.I)
        cleaned = re.sub(r"\s*```$", "", cleaned)
    try:
        return json.loads(cleaned)
    except json.JSONDecodeError:
        start = cleaned.find("{")
        end = cleaned.rfind("}")
        if start >= 0 and end > start:
            return json.loads(cleaned[start : end + 1])
        raise


class BaseProvider:
    name = "base"
    model = "unknown"

    async def extract(self, text: str) -> ProviderResult:
        raise NotImplementedError


class OpenAICompatibleProvider(BaseProvider):
    def __init__(self, *, name: str, base_url: str, model: str, api_key: str = ""):
        self.name = name
        self.base_url = base_url.rstrip("/")
        self.model = model
        self.api_key = api_key

    async def extract(self, text: str) -> ProviderResult:
        schema = CanonicalCv.model_json_schema()
        payload = {
            "model": self.model,
            "temperature": 0,
            "messages": [
                {"role": "system", "content": SYSTEM_PROMPT},
                {
                    "role": "user",
                    "content": "JSON Schema:\n" + json.dumps(schema, ensure_ascii=False) + "\n\nCV:\n" + text,
                },
            ],
            "response_format": {"type": "json_object"},
        }
        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"
        started = time.monotonic()
        async with httpx.AsyncClient(timeout=180) as client:
            response = await client.post(f"{self.base_url}/chat/completions", json=payload, headers=headers)
            response.raise_for_status()
            body = response.json()
        elapsed = int((time.monotonic() - started) * 1000)
        content = body["choices"][0]["message"]["content"]
        cv = CanonicalCv.model_validate(_json_from_text(content))
        return ProviderResult(cv=cv, provider=self.name, model=self.model, inference_ms=elapsed)


class GigaChatProvider(BaseProvider):
    name = "gigachat"

    def __init__(self):
        self.credentials = os.getenv("GIGACHAT_CREDENTIALS", "")
        self.scope = os.getenv("GIGACHAT_SCOPE", "GIGACHAT_API_CORP")
        self.model = os.getenv("GIGACHAT_MODEL", "GigaChat-2-Pro")
        self.base_url = os.getenv("GIGACHAT_BASE_URL", "https://api.giga.chat/v1").rstrip("/")
        self.oauth_url = os.getenv("GIGACHAT_OAUTH_URL", "https://ngw.devices.sberbank.ru:9443/api/v2/oauth")

    async def _token(self, client: httpx.AsyncClient) -> str:
        if not self.credentials:
            raise RuntimeError("GIGACHAT_CREDENTIALS is not configured")
        response = await client.post(
            self.oauth_url,
            data={"scope": self.scope},
            headers={
                "Authorization": f"Basic {self.credentials}",
                "RqUID": str(uuid.uuid4()),
                "Accept": "application/json",
                "Content-Type": "application/x-www-form-urlencoded",
            },
        )
        response.raise_for_status()
        return response.json()["access_token"]

    async def extract(self, text: str) -> ProviderResult:
        schema = CanonicalCv.model_json_schema()
        started = time.monotonic()
        async with httpx.AsyncClient(timeout=180) as client:
            token = await self._token(client)
            payload = {
                "model": self.model,
                "temperature": 0,
                "messages": [
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {
                        "role": "user",
                        "content": "JSON Schema:\n" + json.dumps(schema, ensure_ascii=False) + "\n\nCV:\n" + text,
                    },
                ],
            }
            response = await client.post(
                f"{self.base_url}/chat/completions",
                json=payload,
                headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
            )
            response.raise_for_status()
            body = response.json()
        elapsed = int((time.monotonic() - started) * 1000)
        cv = CanonicalCv.model_validate(_json_from_text(body["choices"][0]["message"]["content"]))
        return ProviderResult(cv=cv, provider=self.name, model=self.model, inference_ms=elapsed)


def get_provider() -> BaseProvider:
    provider = os.getenv("CV_LLM_PROVIDER", "local").strip().lower()
    if provider == "local":
        return OpenAICompatibleProvider(
            name="local",
            base_url=os.getenv("CV_LLM_BASE_URL", "http://cv-llm:8080/v1"),
            model=os.getenv("CV_LLM_MODEL", "cotype-nano-q4"),
            api_key=os.getenv("CV_LLM_API_KEY", ""),
        )
    if provider == "gigachat":
        return GigaChatProvider()
    if provider == "yandex":
        return OpenAICompatibleProvider(
            name="yandex",
            base_url=os.environ["YANDEXGPT_BASE_URL"],
            model=os.getenv("YANDEXGPT_MODEL", "yandexgpt"),
            api_key=os.environ["YANDEXGPT_API_KEY"],
        )
    if provider in {"mws", "cotype-cloud", "openai-compatible"}:
        return OpenAICompatibleProvider(
            name=provider,
            base_url=os.environ["CV_EXTERNAL_LLM_BASE_URL"],
            model=os.environ["CV_EXTERNAL_LLM_MODEL"],
            api_key=os.getenv("CV_EXTERNAL_LLM_API_KEY", ""),
        )
    raise RuntimeError(f"Unsupported CV_LLM_PROVIDER: {provider}")
