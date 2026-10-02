import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import app.settings as settings_module
from app.settings import ProviderSettingsUpdate, load_settings, public_settings, save_settings


class RuntimeSettingsTest(unittest.TestCase):
    def setUp(self):
        self.temp_dir = tempfile.TemporaryDirectory()
        self.path = Path(self.temp_dir.name) / 'settings.json'
        self.path_patch = patch.object(settings_module, 'SETTINGS_PATH', self.path)
        self.path_patch.start()

    def tearDown(self):
        self.path_patch.stop()
        self.temp_dir.cleanup()

    def test_runtime_settings_persist_and_mask_secrets(self):
        with patch.dict(os.environ, {'CV_LLM_PROVIDER': 'local', 'CV_LOCAL_LLM_MODEL': 'Qwen3-4B-Q4_K_M'}, clear=False):
            saved = save_settings(ProviderSettingsUpdate(
                provider='gigachat',
                gigachat={
                    'credentials': 'secret-value',
                    'scope': 'GIGACHAT_API_CORP',
                    'model': 'GigaChat-2-Max',
                },
            ))

            self.assertEqual(saved['provider'], 'gigachat')
            self.assertEqual(load_settings()['gigachat']['credentials'], 'secret-value')

            public = public_settings()
            self.assertNotIn('credentials', public['gigachat'])
            self.assertTrue(public['gigachat']['credentials_configured'])

    def test_empty_secret_keeps_existing_value(self):
        save_settings(ProviderSettingsUpdate(
            provider='gigachat',
            gigachat={'credentials': 'first-secret'},
        ))
        save_settings(ProviderSettingsUpdate(
            provider='gigachat',
            gigachat={'credentials': '', 'model': 'GigaChat-2-Max'},
        ))

        self.assertEqual(load_settings()['gigachat']['credentials'], 'first-secret')


if __name__ == '__main__':
    unittest.main()
