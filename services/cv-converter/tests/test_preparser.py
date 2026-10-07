import unittest
from pathlib import Path

from app.models import CanonicalCv
from app.preparser import merge_preparsed, preparse_cv


FIXTURE = Path(__file__).parent / 'fixtures' / 'technical_qa_lead_blocks.txt'


class PreparserRegressionTest(unittest.TestCase):
    def test_synthetic_layout_fixture_is_split_without_losing_structure(self):
        parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))

        self.assertEqual(parsed.seed.full_name, 'Тестовый Кандидат')
        self.assertEqual(parsed.seed.target_role, 'Technical QA Lead')

        self.assertEqual(parsed.expected_work_experience, 3)
        self.assertEqual([item.company for item in parsed.seed.work_experience], ['TEST COMPANY A', 'TEST COMPANY A', 'TEST COMPANY B'])
        self.assertEqual(
            [item.role for item in parsed.seed.work_experience],
            ['Technical QA Lead', 'QA Engineer / AQA', 'QA Engineer'],
        )

        self.assertEqual(parsed.expected_projects, 3)
        self.assertEqual(
            [item.name for item in parsed.seed.projects],
            ['TEST PROJECT A', 'TEST PROJECT B', 'TEST PROJECT C'],
        )

        self.assertEqual(parsed.seed.contacts.email, 'candidate@example.test')
        self.assertEqual(parsed.seed.contacts.telegram, '@test_candidate')
        self.assertEqual(parsed.seed.contacts.work_format, 'Удалённая работа / Не рассматриваю релокацию')
        self.assertEqual(parsed.seed.languages[0].language, 'Английский')
        self.assertEqual(parsed.seed.languages[0].level, 'b1')
        self.assertIsNotNone(parsed.seed.summary)
        self.assertIn('Shift Left', parsed.seed.summary or '')

        for technology in ('PHP', 'Playwright', 'Selenium', 'PostgreSQL', 'ClickHouse', 'GitLab CI', 'Docker'):
            self.assertIn(technology, parsed.seed.tools)

    def test_preparser_is_authoritative_when_small_llm_drops_structure(self):
        parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))
        llm_result = CanonicalCv(full_name='Тестовый Кандидат', target_role='Technical QA Lead')

        merged = merge_preparsed(llm_result, parsed)

        self.assertEqual(len(merged.work_experience), 3)
        self.assertEqual(len(merged.projects), 3)
        self.assertEqual(merged.contacts.email, 'candidate@example.test')
        self.assertIsNotNone(merged.summary)
        self.assertTrue(any('work_experience нормализован по pre-parser' in warning for warning in merged.warnings))
        self.assertTrue(any('projects нормализованы по pre-parser' in warning for warning in merged.warnings))

    def test_preparser_rejects_llm_duplicates_even_when_counts_match(self):
        parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))
        duplicate = parsed.seed.work_experience[0]
        fake_llm = CanonicalCv(
            work_experience=[duplicate, duplicate, duplicate],
            projects=[parsed.seed.projects[0], parsed.seed.projects[0], parsed.seed.projects[0]],
        )

        merged = merge_preparsed(fake_llm, parsed)

        self.assertEqual([item.role for item in merged.work_experience], ['Technical QA Lead', 'QA Engineer / AQA', 'QA Engineer'])
        self.assertEqual(
            [item.name for item in merged.projects],
            ['ATS/CRM', 'Модуль электронного документооборота (ЭДО)', 'Система управления аутсорсингом'],
        )


if __name__ == '__main__':
    unittest.main()
