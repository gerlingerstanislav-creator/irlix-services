import unittest
from pathlib import Path

from app.models import CanonicalCv
from app.preparser import merge_preparsed, preparse_cv


FIXTURE = Path(__file__).parent / 'fixtures' / 'technical_qa_lead_blocks.txt'


class PreparserRegressionTest(unittest.TestCase):
    def test_real_layout_fixture_is_split_without_losing_structure(self):
        parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))

        self.assertEqual(parsed.expected_work_experience, 3)
        self.assertEqual([item.company for item in parsed.seed.work_experience], ['SENSE IT', 'SENSE IT', 'POISK LLC'])
        self.assertEqual(
            [item.role for item in parsed.seed.work_experience],
            ['Technical QA Lead', 'QA Engineer / AQA', 'QA Engineer'],
        )

        self.assertEqual(parsed.expected_projects, 3)
        self.assertEqual(
            [item.name for item in parsed.seed.projects],
            ['ATS/CRM', 'Модуль электронного документооборота (ЭДО)', 'Система управления аутсорсингом'],
        )

        self.assertEqual(parsed.seed.contacts.email, 'candidate@example.test')
        self.assertEqual(parsed.seed.contacts.telegram, '@candidate')
        self.assertEqual(parsed.seed.contacts.work_format, 'Удалённая работа / Не рассматриваю релокацию')
        self.assertEqual(parsed.seed.languages[0].language, 'Английский')
        self.assertEqual(parsed.seed.languages[0].level, 'b1')
        self.assertIsNotNone(parsed.seed.summary)
        self.assertIn('Shift Left', parsed.seed.summary or '')

        for technology in ('PHP', 'Playwright', 'Selenium', 'PostgreSQL', 'ClickHouse', 'GitLab CI', 'Docker'):
            self.assertIn(technology, parsed.seed.tools)

    def test_preparser_restores_items_missed_by_small_llm(self):
        parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))
        llm_result = CanonicalCv(full_name='Артем Белороссов', target_role='Technical QA Lead')

        merged = merge_preparsed(llm_result, parsed)

        self.assertEqual(len(merged.work_experience), 3)
        self.assertEqual(len(merged.projects), 3)
        self.assertEqual(merged.contacts.email, 'candidate@example.test')
        self.assertIsNotNone(merged.summary)
        self.assertTrue(any('work_experience восстановлен pre-parser' in warning for warning in merged.warnings))
        self.assertTrue(any('projects восстановлены pre-parser' in warning for warning in merged.warnings))


if __name__ == '__main__':
    unittest.main()
