from __future__ import annotations

import json
import os
from pathlib import Path
from threading import RLock
from typing import Any

from pydantic import BaseModel, Field

SETTINGS_PATH = Path(os.getenv('CV_SETTINGS_PATH', '/data/settings.json'))
_LOCK = RLock()
_SECRET_FIELDS = {'credentials', 'api_key'}
_SETTINGS_VERSION = 2
_PERSONAL_FREEMIUM_PROFILE = 'personal_freemium'


class ProviderSettingsUpdate(BaseModel):
    provider: str
    local: dict[str, Any] = Field(default_factory=dict)
    gigachat: dict[str, Any] = Field(default_factory=dict)
    yandex: dict[str, Any] = Field(default_factory=dict)
    openai_compatible: dict[str, Any] = Field(default_factory=dict)


def _defaults() -> dict[str, Any]:
    folder_id = os.getenv('YANDEXGPT_FOLDER_ID', '')
    return {
        '_settings_version': _SETTINGS_VERSION,
        'provider': os.getenv('CV_LLM_PROVIDER', 'local').strip().lower(),
        'local': {
            'base_url': os.getenv('CV_LOCAL_LLM_BASE_URL', 'http://cv-llm:8080/v1'),
            'model': os.getenv('CV_LOCAL_LLM_MODEL', 'Qwen3-4B-Q4_K_M'),
        },
        'gigachat': {
            'credentials': os.getenv('GIGACHAT_CREDENTIALS', ''),
            'scope': os.getenv('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'),
            'model': os.getenv('GIGACHAT_MODEL', 'GigaChat-2-Pro'),
            'base_url': os.getenv('GIGACHAT_BASE_URL', 'https://api.giga.chat'),
            'oauth_url': os.getenv('GIGACHAT_OAUTH_URL', 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth'),
        },
        'yandex': {
            'api_key': os.getenv('YANDEXGPT_API_KEY', ''),
            'folder_id': folder_id,
            'model': os.getenv('YANDEXGPT_MODEL', '') or (f'gpt://{folder_id}/yandexgpt/latest' if folder_id else ''),
            'base_url': os.getenv('YANDEXGPT_BASE_URL', 'https://ai.api.cloud.yandex.net/v1'),
        },
        'openai_compatible': {
            'base_url': os.getenv('CV_EXTERNAL_LLM_BASE_URL', ''),
            'model': os.getenv('CV_EXTERNAL_LLM_MODEL', ''),
            'api_key': os.getenv('CV_EXTERNAL_LLM_API_KEY', ''),
        },
    }


def _merge(base: dict[str, Any], override: dict[str, Any]) -> dict[str, Any]:
    result = json.loads(json.dumps(base))
    for key, value in override.items():
        if isinstance(value, dict) and isinstance(result.get(key), dict):
            result[key].update(value)
        else:
            result[key] = value
    return result


def _write_settings(value: dict[str, Any]) -> None:
    SETTINGS_PATH.parent.mkdir(parents=True, exist_ok=True)
    tmp = SETTINGS_PATH.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, ensure_ascii=False, indent=2), encoding='utf-8')
    os.chmod(tmp, 0o600)
    tmp.replace(SETTINGS_PATH)


def _migrate_persisted(persisted: dict[str, Any]) -> dict[str, Any]:
    version = int(persisted.get('_settings_version') or 1)
    if version >= _SETTINGS_VERSION:
        return persisted

    if os.getenv('CV_GIGACHAT_PROFILE', '').strip().lower() == _PERSONAL_FREEMIUM_PROFILE:
        gigachat = persisted.setdefault('gigachat', {})
        # Migrate only the old defaults that we previously recommended for this stand.
        # Credentials and any unrelated provider settings remain untouched.
        if gigachat.get('scope') in {None, '', 'GIGACHAT_API_CORP'}:
            gigachat['scope'] = 'GIGACHAT_API_PERS'
        if gigachat.get('model') in {None, '', 'GigaChat-2-Max'}:
            gigachat['model'] = 'GigaChat-2-Pro'

    persisted['_settings_version'] = _SETTINGS_VERSION
    _write_settings(persisted)
    return persisted


def load_settings() -> dict[str, Any]:
    with _LOCK:
        defaults = _defaults()
        if not SETTINGS_PATH.exists():
            return defaults
        try:
            persisted = json.loads(SETTINGS_PATH.read_text(encoding='utf-8'))
        except (OSError, json.JSONDecodeError):
            return defaults
        persisted = _migrate_persisted(persisted)
        return _merge(defaults, persisted)


def save_settings(update: ProviderSettingsUpdate) -> dict[str, Any]:
    with _LOCK:
        current = load_settings()
        incoming = update.model_dump()
        # Empty secret values mean “keep the currently stored value”.
        for section in ('gigachat', 'yandex', 'openai_compatible'):
            for field in _SECRET_FIELDS:
                if field in incoming.get(section, {}) and incoming[section][field] == '':
                    incoming[section].pop(field)
        merged = _merge(current, incoming)
        merged['_settings_version'] = _SETTINGS_VERSION
        _write_settings(merged)
        return merged


def public_settings(settings: dict[str, Any] | None = None) -> dict[str, Any]:
    value = json.loads(json.dumps(settings or load_settings()))
    for key in list(value):
        if key.startswith('_'):
            value.pop(key, None)
    for section in ('gigachat', 'yandex', 'openai_compatible'):
        block = value.get(section, {})
        for field in _SECRET_FIELDS:
            if field in block:
                block[f'{field}_configured'] = bool(block.get(field))
                block.pop(field, None)
    return value
