from __future__ import annotations

import json
import os
import time
import uuid
from abc import ABC, abstractmethod

import httpx
from pydantic import ValidationError

from .models import CanonicalCv
from .preparser import merge_preparsed, preparse_cv
from .settings import load_settings

SYSTEM_PROMPT = """Ты нормализуешь уже предварительно разобранное CV в строго заданную JSON-структуру.
Вход разделён на секции [PROFILE], [WORK_EXPERIENCE], [PROJECTS], [SKILLS].
Правила:
- не придумывай и не улучшай факты;
- не меняй смысл формулировок;
- если данных нет, используй null или пустой массив;
- full_name — ФИО/имя кандидата из [PROFILE];
- target_role — профессия/целевая должность кандидата из [PROFILE];
- summary — профессиональное описание кандидата, не список обязанностей;
- contacts заполняй только явно присутствующими контактами и форматом работы;
- work_experience — именно места работы/должности из [WORK_EXPERIENCE]; не превращай их в projects;
- projects — только проекты из [PROJECTS]; не используй имя кандидата, название секции или работодателя как название проекта;
- сохрани каждую явно указанную работу и каждый явно указанный проект отдельным элементом;
- responsibilities должны содержать отдельные задачи/факты без маркеров списка;
- achievements — только явно сформулированные результаты, ничего не выводи логически;
- technologies/tools должны содержать только явно названные технологии и инструменты;
- skill_groups можешь группировать только когда такая группировка очевидна из исходника; иначе используй tools;
- warnings используй только для неоднозначностей или явных проблем исходного документа;
- верни только JSON без markdown.
"""

REPAIR_SUFFIX = """
Предыдущая генерация не прошла JSON/Cv schema validation или была обрезана по лимиту токенов. Сгенерируй результат заново.
Верни один синтаксически корректный JSON-объект без markdown и комментариев.
Все имена свойств и строковые значения должны быть в двойных кавычках; не оставляй trailing comma.
Строго соблюдай переданную JSON Schema.
Пиши JSON максимально компактно: без форматирования, отступов и лишних пробелов, чтобы ответ не был обрезан.
"""

GIGACHAT_DEFAULT_MAX_OUTPUT_TOKENS = 12000
GIGACHAT_TRUNCATION_RETRY_MAX_OUTPUT_TOKENS = 24000


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


def _validate_message(data: dict) -> CanonicalCv:
    return CanonicalCv.model_validate(_extract_json(data['choices'][0]['message']['content']))


def _finish_reason(data: dict) -> str:
    try:
        return str(data['choices'][0].get('finish_reason') or 'unknown')
    except (KeyError, IndexError, TypeError):
        return 'unknown'


def _fill_header_fallback(cv: CanonicalCv, profile_text: str) -> CanonicalCv:
    if cv.target_role and cv.full_name:
        return cv
    blocks = [block.strip() for block in profile_text.split('\n\n') if block.strip()]
    if not blocks:
        return cv
    if not cv.target_role:
        for block in blocks[:4]:
            lines = [line.strip() for line in block.splitlines() if line.strip()]
            if len(lines) != 1:
                continue
            value = lines[0]
            lower = value.casefold()
            if ':' in value or '@' in value or any(ch.isdigit() for ch in value):
                continue
            if lower.startswith(('формат', 'telegram', 'email', 'телефон', 'английский', 'english')):
                continue
            if 2 <= len(value.split()) <= 8 and len(value) <= 100:
                cv.target_role = value
                cv.warnings.append('target_role восстановлен из структурного заголовка после пропуска LLM')
                break
    return cv


class CvExtractionProvider(ABC):
    name: str

    @abstractmethod
    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        raise NotImplementedError

    async def health(self) -> dict:
        return {'status': 'configured', 'provider': self.name}


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
            'max_tokens': int(os.getenv('CV_LLM_MAX_OUTPUT_TOKENS', '2600')),
        }
        if constrained:
            payload['response_format'] = {
                'type': 'json_schema',
                'json_schema': {'name': 'canonical_cv', 'strict': True, 'schema': _schema()},
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

    async def health(self) -> dict:
        async with httpx.AsyncClient(timeout=10) as client:
            response = await client.get(f'{self.base_url}/models', headers=self._headers())
            response.raise_for_status()
            data = response.json()
        return {'status': 'ok', 'provider': self.name, 'model': self.model, 'models': data.get('data', [])}

    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        preparse_started = time.perf_counter()
        parsed = preparse_cv(source_text)
        prompt_text = parsed.prompt_text()
        preparse_ms = round((time.perf_counter() - preparse_started) * 1000)

        timeout = float(os.getenv('CV_LLM_TIMEOUT_SECONDS', '300'))
        data = None
        first_error: Exception | None = None
        llm_ms = 0
        validation_ms = 0
        attempts = 0
        async with httpx.AsyncClient(timeout=timeout) as client:
            try:
                attempts = 1
                request_started = time.perf_counter()
                data = await self._request(client, prompt_text, constrained=True)
                llm_ms += round((time.perf_counter() - request_started) * 1000)
                validation_started = time.perf_counter()
                try:
                    cv = _validate_message(data)
                finally:
                    validation_ms += round((time.perf_counter() - validation_started) * 1000)
            except (httpx.HTTPError, KeyError, ValueError, ValidationError) as exc:
                first_error = exc
                attempts = 2
                request_started = time.perf_counter()
                data = await self._request(client, prompt_text, constrained=False)
                llm_ms += round((time.perf_counter() - request_started) * 1000)
                validation_started = time.perf_counter()
                try:
                    cv = _validate_message(data)
                except (KeyError, ValueError, ValidationError) as retry_exc:
                    raise ValueError(f'LLM returned invalid CanonicalCv after retry: {retry_exc}; first attempt: {first_error}') from retry_exc
                finally:
                    validation_ms += round((time.perf_counter() - validation_started) * 1000)

        post_started = time.perf_counter()
        cv = _fill_header_fallback(cv, parsed.profile_text)
        cv = merge_preparsed(cv, parsed)
        postprocess_ms = round((time.perf_counter() - post_started) * 1000)
        usage = data.get('usage') or {}
        return cv, {
            'provider': self.name,
            'model': data.get('model') or self.model,
            'preparse_ms': preparse_ms,
            'llm_ms': llm_ms,
            'validation_ms': validation_ms,
            'postprocess_ms': postprocess_ms,
            'llm_attempts': attempts,
            'input_tokens': usage.get('prompt_tokens') or usage.get('input_tokens'),
            'output_tokens': usage.get('completion_tokens') or usage.get('output_tokens'),
            'expected_work_experience': parsed.expected_work_experience,
            'expected_projects': parsed.expected_projects,
            'preparser_notes': parsed.notes,
        }


class GigaChatProvider(CvExtractionProvider):
    name = 'gigachat'

    def __init__(self, config: dict):
        self.credentials = config.get('credentials') or ''
        self.scope = config.get('scope') or 'GIGACHAT_API_CORP'
        self.model = config.get('model') or 'GigaChat-2-Max'
        self.base_url = (config.get('base_url') or 'https://api.giga.chat').rstrip('/')
        self.oauth_url = config.get('oauth_url') or 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'
        if not self.credentials:
            raise RuntimeError('GigaChat credentials are not configured')

    async def _token(self, client: httpx.AsyncClient) -> str:
        response = await client.post(
            self.oauth_url,
            headers={'Authorization': f'Basic {self.credentials}', 'RqUID': str(uuid.uuid4()), 'Accept': 'application/json'},
            data={'scope': self.scope},
        )
        response.raise_for_status()
        return response.json()['access_token']

    def _output_token_limit(self, *, truncation_retry: bool = False) -> int:
        configured = int(os.getenv('CV_GIGACHAT_MAX_OUTPUT_TOKENS', str(GIGACHAT_DEFAULT_MAX_OUTPUT_TOKENS)))
        if not truncation_retry:
            return configured
        return max(configured, GIGACHAT_TRUNCATION_RETRY_MAX_OUTPUT_TOKENS)

    def _payload(self, prompt_text: str, *, repair: bool = False, truncation_retry: bool = False) -> dict:
        system = SYSTEM_PROMPT + (REPAIR_SUFFIX if repair else '')
        return {
            'model': self.model,
            'messages': [
                {'role': 'system', 'content': system},
                {'role': 'user', 'content': prompt_text},
            ],
            'temperature': 0,
            'max_tokens': self._output_token_limit(truncation_retry=truncation_retry),
            'response_format': {'type': 'json_schema', 'schema': _schema(), 'strict': True},
        }

    async def _request(
        self,
        client: httpx.AsyncClient,
        token: str,
        prompt_text: str,
        *,
        repair: bool = False,
        truncation_retry: bool = False,
    ) -> dict:
        response = await client.post(
            f'{self.base_url}/v1/chat/completions',
            headers={'Authorization': f'Bearer {token}', 'Content-Type': 'application/json'},
            json=self._payload(prompt_text, repair=repair, truncation_retry=truncation_retry),
        )
        response.raise_for_status()
        return response.json()

    async def health(self) -> dict:
        async with httpx.AsyncClient(timeout=20) as client:
            token = await self._token(client)
            response = await client.get(
                f'{self.base_url}/v1/models',
                headers={'Authorization': f'Bearer {token}', 'Accept': 'application/json'},
            )
            response.raise_for_status()
        return {'status': 'ok', 'provider': self.name, 'model': self.model}

    async def extract(self, source_text: str) -> tuple[CanonicalCv, dict]:
        preparse_started = time.perf_counter()
        parsed = preparse_cv(source_text)
        prompt_text = parsed.prompt_text()
        preparse_ms = round((time.perf_counter() - preparse_started) * 1000)

        llm_ms = 0
        validation_ms = 0
        attempts = 0
        errors: list[str] = []
        data: dict = {}
        truncation_retry = False
        async with httpx.AsyncClient(timeout=float(os.getenv('CV_LLM_TIMEOUT_SECONDS', '300'))) as client:
            token = await self._token(client)
            for attempt in (1, 2, 3):
                attempts = attempt
                request_started = time.perf_counter()
                data = await self._request(
                    client,
                    token,
                    prompt_text,
                    repair=attempt > 1,
                    truncation_retry=truncation_retry,
                )
                llm_ms += round((time.perf_counter() - request_started) * 1000)

                reason = _finish_reason(data)
                if reason == 'length':
                    errors.append(f'attempt {attempt}: response truncated (finish_reason=length)')
                    if attempt < 3:
                        truncation_retry = True
                        continue
                    raise ValueError(
                        'GigaChat response remained truncated after 3 attempts '
                        f'(max_tokens={self._output_token_limit(truncation_retry=True)}): ' + '; '.join(errors)
                    )

                validation_started = time.perf_counter()
                try:
                    cv = _validate_message(data)
                    validation_ms += round((time.perf_counter() - validation_started) * 1000)
                    break
                except (KeyError, ValueError, ValidationError) as exc:
                    validation_ms += round((time.perf_counter() - validation_started) * 1000)
                    errors.append(f'attempt {attempt}: {exc}')
                    if attempt == 1:
                        continue
                    raise ValueError(
                        f'GigaChat returned invalid CanonicalCv after {attempt} attempts '
                        f'(finish_reason={reason}): {exc}; previous attempts: ' + '; '.join(errors[:-1])
                    ) from exc

        post_started = time.perf_counter()
        cv = _fill_header_fallback(cv, parsed.profile_text)
        cv = merge_preparsed(cv, parsed)
        postprocess_ms = round((time.perf_counter() - post_started) * 1000)
        usage = data.get('usage') or {}
        return cv, {
            'provider': self.name,
            'model': data.get('model') or self.model,
            'preparse_ms': preparse_ms,
            'llm_ms': llm_ms,
            'validation_ms': validation_ms,
            'postprocess_ms': postprocess_ms,
            'llm_attempts': attempts,
            'input_tokens': usage.get('prompt_tokens'),
            'output_tokens': usage.get('completion_tokens'),
            'expected_work_experience': parsed.expected_work_experience,
            'expected_projects': parsed.expected_projects,
            'preparser_notes': parsed.notes,
        }


def build_provider(settings: dict | None = None) -> CvExtractionProvider:
    config = settings or load_settings()
    provider = str(config.get('provider') or 'local').strip().lower()
    if provider == 'local':
        local = config.get('local', {})
        return OpenAiCompatibleProvider(
            name='local',
            base_url=local.get('base_url') or 'http://cv-llm:8080/v1',
            model=local.get('model') or 'Qwen3-4B-Q4_K_M',
        )
    if provider == 'gigachat':
        return GigaChatProvider(config.get('gigachat', {}))
    if provider == 'yandex':
        yandex = config.get('yandex', {})
        api_key = yandex.get('api_key') or ''
        folder_id = yandex.get('folder_id') or ''
        if not api_key or not folder_id:
            raise RuntimeError('Yandex AI Studio credentials are not configured')
        model = yandex.get('model') or f'gpt://{folder_id}/yandexgpt/latest'
        return OpenAiCompatibleProvider(
            name='yandex',
            base_url=yandex.get('base_url') or 'https://ai.api.cloud.yandex.net/v1',
            model=model,
            api_key=api_key,
            extra_headers={'x-folder-id': folder_id},
        )
    if provider in {'mws', 'openai_compatible'}:
        external = config.get('openai_compatible', {})
        base_url = external.get('base_url') or ''
        model = external.get('model') or ''
        if not base_url or not model:
            raise RuntimeError('OpenAI-compatible provider endpoint/model are not configured')
        return OpenAiCompatibleProvider(
            name=provider,
            base_url=base_url,
            model=model,
            api_key=external.get('api_key') or None,
        )
    raise RuntimeError(f'Unsupported CV_LLM_PROVIDER: {provider}')
