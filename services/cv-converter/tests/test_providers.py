from __future__ import annotations

import json
import unittest

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
        repairs = []

        async def fake_token(_client):
            return 'test-token'

        async def fake_request(_client, token, prompt_text, *, repair=False):
            self.assertEqual(token, 'test-token')
            self.assertTrue(prompt_text)
            repairs.append(repair)
            return responses.pop(0)

        provider._token = fake_token
        provider._request = fake_request

        cv, metrics = await provider.extract('Иван Петров\nQA Engineer\nНавыки: Python')

        self.assertEqual(cv.full_name, 'Иван Петров')
        self.assertEqual(cv.target_role, 'QA Engineer')
        self.assertEqual(metrics['llm_attempts'], 2)
        self.assertEqual(repairs, [False, True])
        self.assertGreaterEqual(metrics['validation_ms'], 0)
        self.assertGreaterEqual(metrics['llm_ms'], 0)

    async def test_reports_finish_reason_after_second_invalid_response(self):
        provider = GigaChatProvider({'credentials': 'test-credentials', 'model': 'test-model'})
        responses = [
            {'choices': [{'message': {'content': '{broken}'}, 'finish_reason': 'stop'}]},
            {'choices': [{'message': {'content': '{still broken}'}, 'finish_reason': 'length'}]},
        ]

        async def fake_token(_client):
            return 'test-token'

        async def fake_request(_client, _token, _prompt_text, *, repair=False):
            return responses.pop(0)

        provider._token = fake_token
        provider._request = fake_request

        with self.assertRaisesRegex(ValueError, 'finish_reason=length'):
            await provider.extract('Иван Петров\nQA Engineer')


if __name__ == '__main__':
    unittest.main()
