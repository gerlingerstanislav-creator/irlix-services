from app.settings import ProviderSettingsUpdate, load_settings, public_settings, save_settings
import app.settings as settings_module


def test_runtime_settings_persist_and_mask_secrets(tmp_path, monkeypatch):
    path = tmp_path / 'settings.json'
    monkeypatch.setattr(settings_module, 'SETTINGS_PATH', path)
    monkeypatch.setenv('CV_LLM_PROVIDER', 'local')
    monkeypatch.setenv('CV_LOCAL_LLM_MODEL', 'Qwen3-4B-Q4_K_M')

    saved = save_settings(ProviderSettingsUpdate(
        provider='gigachat',
        gigachat={
            'credentials': 'secret-value',
            'scope': 'GIGACHAT_API_CORP',
            'model': 'GigaChat-2-Max',
        },
    ))

    assert saved['provider'] == 'gigachat'
    assert load_settings()['gigachat']['credentials'] == 'secret-value'

    public = public_settings()
    assert 'credentials' not in public['gigachat']
    assert public['gigachat']['credentials_configured'] is True


def test_empty_secret_keeps_existing_value(tmp_path, monkeypatch):
    path = tmp_path / 'settings.json'
    monkeypatch.setattr(settings_module, 'SETTINGS_PATH', path)

    save_settings(ProviderSettingsUpdate(
        provider='gigachat',
        gigachat={'credentials': 'first-secret'},
    ))
    save_settings(ProviderSettingsUpdate(
        provider='gigachat',
        gigachat={'credentials': '', 'model': 'GigaChat-2-Max'},
    ))

    assert load_settings()['gigachat']['credentials'] == 'first-secret'
