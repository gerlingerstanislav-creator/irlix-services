from __future__ import annotations

import json
import os
import time
import uuid
from abc import ABC, abstractmethod

import httpx
from pydantic import ValidationError

from .models import CanonicalCv

SYSTEM_PROMPT = """Ты извлекаешь данные из CV в строго заданную JSON-структуру.
Правила:
- не придумывай и не улучшай факты;
- не меняй смысл формулировок;
- если данных нет, используй null или пустой массив;
- сохрани все существенные сведения исходного CV; то, что не помещается в основные поля, перенеси в extra_sections;
- responsibilities должны содержать отдельные задачи без маркеров списка;
- technologies должны содержать отдельные технологии/инструменты;
- warnings используй только для неоднозначностей или явных проблем исходного документа;
- верни только JSON без markdown.
"""


def _schema() -> dict:
    schema = CanonicalCv.model_json_schema()
    schema['additionalProperties'] = False
    return schema


def _extract_json(text: str) -> dict:
    value = text.strip()
    if value.startswith('```'):
        value = value.strip('`')
        if value.lower().startswith('json'):
            value = value[4:].lstrip()
    start = value.find('{')
    end = value.rfind('}')
    if start < 0 or end < start:
        raise ValueError('LLM did not return JSON')
    return json.loads(value[start:end + 1])


class CvExtractionProvider(ABC):
    name: str

    @abstractmethod
    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        raise NotImplementedError


class OpenAiCompatibleProvider(CvExtractionProvider):
    def __init__(self, *, name: str, base_url: str, model: str, api_key: str | None = None, extra_headers: dict | None = None):
        self.name = name
        self.base_url = base_url.rstrip('/')
        self.model = model
        self.api_key = api_key
        self.extra_headers = extra_headers or {}

    def _headers(self) -> dict:
        headers = {'Content-Type': 'application/json', **self.extra_headers}
        if self.api_key:
            headers['Authorization'] = f'Bearer {self.api_key}'
        return headers

    def _payload(self, source_text: str, *, constrained: bool) -> dict:
        system = SYSTEM_PROMPT
        if not constrained:
            system += '\nJSON Schema:\n' + json.dumps(_schema(), ensure_ascii=False, separators=(',', ':'))
        payload = {
            'model': self.model,
            'messages': [
                {'role': 'system', 'content': system},
                {'role': 'user', 'content': source_text},
            ],
            'temperature': 0,
            'max_tokens': int(os.getenv('CV_LLM_MAX_OUTPUT_TOKENS', '5000')),
        }
        if constrained:
            payload['response_format'] = {
                'type': 'json_schema',
                'json_schema': {
                    'name': 'canonical_cv',
                    'strict': True,
                    'schema': _schema(),
                },
            }
        return payload

    async def _request(self, client: httpx.AsyncClient, source_text: str, *, constrained: bool) -> dict:
        response = await client.post(
            f'{self.base_url}/chat/completions',
            headers=self._headers(),
            json=self._payload(source_text, constrained=constrained),
        )
        response.raise_for_status()
        return response.json()

    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        started = time.perf_counter()
        timeout = float(os.getenv('CV_LLM_TIMEOUT_SECONDS', '180'))
        data = None
        first_error: Exception | None = None
        async with httpx.AsyncClient(timeout=timeout) as client:
            try:
                data = await self._request(client, source_text, constrained=True)
                cv = CanonicalCv.model_validate(_extract_json(data['choices'][0]['message']['content']))
            except (httpx.HTTPError, KeyError, ValueError, ValidationError) as exc:
                first_error = exc
                data = await self._request(client, source_text, constrained=False)
                try:
                    cv = CanonicalCv.model_validate(_extract_json(data['choices'][0]['message']['content']))
                except (KeyError, ValueError, ValidationError) as retry_exc:
                    raise ValueError(f'LLM returned invalid CanonicalCv after retry: {retry_exc}; first attempt: {first_error}') from retry_exc
        usage = data.get('usage') or {}
        return cv, {
            'provider': self.name,
            'model': data.get('model') or self.model,
            'llm_ms': round((time.perf_counter() - started) * 1000),
            'input_tokens': usage.get('prompt_tokens') or usage.get('input_tokens'),
            'output_tokens': usage.get('completion_tokens') or usage.get('output_tokens'),
        }


class GigaChatProvider(CvExtractionProvider):
    name = 'gigachat'

    def __init__(self):
        self.credentials = os.environ['GIGACHAT_CREDENTIALS']
        self.scope = os.getenv('GIGACHAT_SCOPE', 'GIGACHAT_API_CORP')
        self.model = os.getenv('GIGACHAT_MODEL', 'GigaChat-2-Max')
        self.base_url = os.getenv('GIGACHAT_BASE_URL', 'https://api.giga.chat').rstrip('/')
        self.oauth_url = os.getenv('GIGACHAT_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth')

    async def _token(self, client: httpx.AsyncClient) -> str:
        response = await client.post(
            self.oauth_url,
            headers={'Authorization': f'Basic {self.credentials}', 'RqUID': str(uuid.uuid4()), 'Accept': 'application/json'},
            data={'scope': self.scope},
        )
        response.raise_for_status()
        return response.json()['access_token']

    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        started = time.perf_counter()
        async with httpx.AsyncClient(timeout=float(os.getenv('CV_LLM_TIMEOUT_SECONDS', '180'))) as client:
            token = await self._token(client)
            payload = {
                'model': self.model,
                'messages': [
                    {'role': 'system', 'content': SYSTEM_PROMPT},
                    {'role': 'user', 'content': source_text},
                ],
                'temperature': 0,
                'response_format': {'type': 'json_schema', 'schema': _schema(), 'strict': True},
            }
            response = await client.post(
                f'{self.base_url}/v1/chat/completions',
                headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'},
                json=payload,
            )
            response.raise_for_status()
        data = response.json()
        cv = CanonicalCv.model_validate(_extract_json(data['choices'][0]['message']['content']))
        usage = data.get('usage') or {}
        return cv, {
            'provider': self.name,
            'model': data.get('model') or self.model,
            'llm_ms': round((time.perf_counter() - started) * 1000),
            'input_tokens': usage.get('prompt_tokens'),
            'output_tokens': usage.get('completion_tokens'),
        }


def build_provider() -> CvExtractionProvider:
    provider = os.getenv('CV_LLM_PROVIDER', 'local').strip().lower()
    if provider == 'local':
        return OpenAiCompatibleProvider(
            name='local',
            base_url=os.getenv('CV_LOCAL_LLM_BASE_URL', 'http://cv-llm:8080/v1'),
            model=os.getenv('CV_LOCAL_LLM_MODEL', 'Cotype-Nano-Q3_K_M'),
        )
    if provider == 'gigachat':
        return GigaChatProvider()
    if provider == 'yandex':
        api_key = os.environ['YANDEXGPT_API_KEY']
        folder_id = os.environ['YANDEXGPT_FOLDER_ID']
        model = os.getenv('YANDEXGPT_MODEL') or f'gpt://{folder_id}/yandexgpt/latest'
        return OpenAiCompatibleProvider(
            name='yandex',
            base_url=os.getenv('YANDEXGPT_BASE_URL', 'https://ai.api.cloud.yandex.net/v1'),
            model=model,
            api_key=api_key,
            extra_headers={'x-folder-id': folder_id},
        )
    if provider in {'mws', 'openai_compatible'}:
        return OpenAiCompatibleProvider(
            name=provider,
            base_url=os.environ['CV_EXTERNAL_LLM_BASE_URL'],
            model=os.environ['CV_EXTERNAL_LLM_MODEL'],
            api_key=os.getenv('CV_EXTERNAL_LLM_API_KEY'),
        )
    raise RuntimeError(f'Unsupported CV_LLM_PROVIDER: {provider}')
