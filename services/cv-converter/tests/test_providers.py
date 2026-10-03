from __future__ import annotations

import json
import os
import unittest
from unittest.mock import patch

from app.providers import GigaChatProvider


VALID_CV = {
    'full_name': 'Иван Петров',
    'target_role': 'QA Engineer',
    'source_language': 'ru',
    'contacts': {},
    'summary': None,
    'experience': {},
    'work_experience': [],
    'skill_groups': [],
    'tools': ['Python'],
    'education': [],
    'languages': [],
    'projects': [],
    'additional': [],
    'extra_sections': [],
    'warnings': [],
}


class GigaChatProviderRetryTest(unittest.IsolatedAsyncioTestCase):
    async def test_retries_malformed_json_and_returns_valid_cv(self):
        provider = GigaChatProvider({'credentials': 'test-credentials', 'model': 'test-model'})
        responses = [
            {
                'model': 'test-model',
                'choices': [{'message': {'content': '{"full_name":"Иван Петров", broken}'}, 'finish_reason': 'stop'}],
                'usage': {},
            },
            {
                'model': 'test-model',
                'choices': [{'message': {'content': json.dumps(VALID_CV, ensure_ascii=False)}, 'finish_reason': 'stop'}],
                'usage': {'prompt_tokens': 10, 'completion_tokens': 20},
            },
        ]
        calls = []

        async def fake_token(_client):
            return 'test-token'

        async def fake_request(_client, token, prompt_text, *, repair=False, truncation_retry=False):
            self.assertEqual(token, 'test-token')
            self.assertTrue(prompt_text)
            calls.append((repair, truncation_retry))
            return responses.pop(0)

        provider._token = fake_token
        provider._request = fake_request

        cv, metrics = await provider.extract('Иван Петров\nQA Engineer\nНавыки: Python')

        self.assertEqual(cv.full_name, 'Иван Петров')
        self.assertEqual(cv.target_role, 'QA Engineer')
        self.assertEqual(metrics['llm_attempts'], 2)
        self.assertEqual(calls, [(False, False), (True, False)])
        self.assertGreaterEqual(metrics['validation_ms'], 0)
        self.assertGreaterEqual(metrics['llm_ms'], 0)

    async def test_retries_truncated_response_with_expanded_limit(self):
        provider = GigaChatProvider({'credentials': 'test-credentials', 'model': 'test-model'})
        responses = [
            {
                'model': 'test-model',
                'choices': [{'message': {'content': '{"full_name":"Иван Петров", broken}'}, 'finish_reason': 'stop'}],
                'usage': {},
            },
            {
                'model': 'test-model',
                'choices': [{'message': {'content': '{"full_name":"Иван Петров"'}, 'finish_reason': 'length'}],
                'usage': {'completion_tokens': 12000},
            },
            {
                'model': 'test-model',
                'choices': [{'message': {'content': json.dumps(VALID_CV, ensure_ascii=False)}, 'finish_reason': 'stop'}],
                'usage': {'prompt_tokens': 10, 'completion_tokens': 4000},
            },
        ]
        calls = []

        async def fake_token(_client):
            return 'test-token'

        async def fake_request(_client, _token, _prompt_text, *, repair=False, truncation_retry=False):
            calls.append((repair, truncation_retry))
            return responses.pop(0)

        provider._token = fake_token
        provider._request = fake_request

        cv, metrics = await provider.extract('Иван Петров\nQA Engineer\nНавыки: Python')

        self.assertEqual(cv.full_name, 'Иван Петров')
        self.assertEqual(metrics['llm_attempts'], 3)
        self.assertEqual(calls, [(False, False), (True, False), (True, True)])

    async def test_payload_sets_explicit_gigachat_output_limit(self):
        provider = GigaChatProvider({'credentials': 'test-credentials', 'model': 'test-model'})
        with patch.dict(os.environ, {'CV_GIGACHAT_MAX_OUTPUT_TOKENS': '14000'}):
            regular = provider._payload('text')
            expanded = provider._payload('text', repair=True, truncation_retry=True)

        self.assertEqual(regular['max_tokens'], 14000)
        self.assertEqual(expanded['max_tokens'], 24000)
        self.assertIn('максимально компактно', expanded['messages'][0]['content'])

    async def test_reports_length_after_three_truncated_responses(self):
        provider = GigaChatProvider({'credentials': 'test-credentials', 'model': 'test-model'})
        responses = [
            {'choices': [{'message': {'content': '{broken}'}, 'finish_reason': 'length'}]},
            {'choices': [{'message': {'content': '{still broken}'}, 'finish_reason': 'length'}]},
            {'choices': [{'message': {'content': '{still truncated}'}, 'finish_reason': 'length'}]},
        ]

        async def fake_token(_client):
            return 'test-token'

        async def fake_request(_client, _token, _prompt_text, *, repair=False, truncation_retry=False):
            return responses.pop(0)

        provider._token = fake_token
        provider._request = fake_request

        with self.assertRaisesRegex(ValueError, 'remained truncated after 3 attempts'):
            await provider.extract('Иван Петров\nQA Engineer')


if __name__ == '__main__':
    unittest.main()
