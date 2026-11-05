"""Regressions for DOCX CVs whose actual project history is in tables.

Only synthetic people, companies and dates may be committed to this public repo.
"""
import unittest
from io import BytesIO

from docx import Document

from app.extractor import extract_text
from app.models import CanonicalCv, ProjectItem
from app.preparser import merge_preparsed, preparse_cv
from app.renderer import render_docx


def two_column_cv() -> bytes:
    document = Document()
    for line in [
        'Тестовый Кандидат', 'Системный аналитик',
        'Опыт коммерческой разработки: более 6 лет',
        'Системный аналитик: 6 лет (1 проект)',
        'Навыки и умения:', 'Ключевые навыки:',
        'Сбор и анализ требований:', 'Сбор требований', '(интервью и анализ документов)',
        'Проектирование API', 'ТЗ и документация:', 'Подготовка спецификаций',
        'Используемые инструменты:', 'Postman', 'Draw.io',
        'Образование:', 'Образование:', 'Тестовый университет',
        'Бакалавриат, "Информационные системы"', '09.2017 - 06.2021',
        'Иностранные языки:', 'Русский - родной', 'Английский – B2',
        'Опыт работы — более 5 лет',
    ]:
        document.add_paragraph(line)

    for number, explicit_result in ((1, True), (2, False)):
        table = document.add_table(rows=2, cols=2)
        left = table.cell(0, 0).merge(table.cell(1, 0))
        right = table.cell(0, 1).merge(table.cell(1, 1))
        for text in ['Системный аналитик', f'01.202{number} - 12.202{number}',
                     'Команда 1 analyst, 2 backend, 1 qa', 'Postman, OpenAPI']:
            left.add_paragraph(text)
        for text in [
            f'Тестовая организация {number}', f'Тестовый проект {number}',
            'Описание проекта', f'Описание проекта {number} без домыслов.',
            'Выполняемые задачи', f'Задача {number}.1', f'Задача {number}.2',
        ]:
            right.add_paragraph(text)
        if explicit_result:
            right.add_paragraph('Результат')
            right.add_paragraph('Согласован интерфейс.')
    for text in [
        'Дополнительная информация', 'Что изучаю?', 'Новые методы аналитики.',
        'Чего хочу?', 'Глубже изучить API.',
    ]:
        document.add_paragraph(text)
    output = BytesIO()
    document.save(output)
    return output.getvalue()


class TwoColumnCvTest(unittest.TestCase):
    def test_ordered_extraction_avoids_merged_row_duplication(self):
        source = extract_text('synthetic.docx', two_column_cv())
        self.assertEqual(source.count('[CV_PROJECT_START]'), 2)
        self.assertEqual(source.count('[TASK]'), 4)
        self.assertLess(source.index('Опыт работы'), source.index('[CV_PROJECT_START]'))
        self.assertGreater(source.index('Дополнительная информация'), source.index('[CV_PROJECT_END]'))

    def test_source_facts_survive_empty_or_inventive_llm(self):
        parsed = preparse_cv(extract_text('synthetic.docx', two_column_cv()))
        self.assertTrue(parsed.structured_document)
        self.assertEqual(parsed.expected_projects, 2)
        self.assertEqual([x.name for x in parsed.seed.projects], [
            'Тестовая организация 1 — Тестовый проект 1',
            'Тестовая организация 2 — Тестовый проект 2',
        ])
        self.assertEqual([len(x.responsibilities) for x in parsed.seed.projects], [2, 2])
        self.assertEqual(parsed.seed.projects[0].results, ['Согласован интерфейс.'])
        self.assertEqual(parsed.seed.projects[1].results, [])
        self.assertEqual(parsed.seed.experience.role, '6 лет')
        self.assertEqual(parsed.seed.experience.commercial, 'более 6 лет')
        self.assertEqual(len(parsed.seed.skill_groups), 2)
        self.assertEqual(parsed.seed.skill_groups[0].items[0], 'Сбор требований (интервью и анализ документов)')
        self.assertEqual(parsed.seed.tools, ['Postman', 'Draw.io'])
        self.assertEqual(parsed.seed.education[0].institution, 'Тестовый университет')
        self.assertEqual([x.language for x in parsed.seed.languages], ['Русский', 'Английский'])
        self.assertEqual(len(parsed.seed.additional), 2)
        self.assertTrue(any('заявлено 1 проектов' in w for w in parsed.seed.warnings))

        invented = CanonicalCv(
            full_name='Вымышленное имя',
            summary='Несуществующее профессиональное достижение',
            projects=[ProjectItem(name='Тестовая организация 1 — Тестовый проект 1',
                                  results=['Вымышленный результат'])],
        )
        merged = merge_preparsed(invented, parsed)
        self.assertEqual(merged.full_name, 'Тестовый Кандидат')
        self.assertIsNone(merged.summary)
        self.assertEqual(len(merged.projects), 2)
        self.assertEqual(merged.projects[0].results, ['Согласован интерфейс.'])
        self.assertEqual(merged.projects[1].results, [])
        self.assertFalse(any('Вымышленный' in value for project in merged.projects
                             for value in project.results))
        self.assertIn('Тестовая организация 2', merged.projects[1].name)
        self.assertEqual(len(merged.additional), 2)

        final = Document(BytesIO(render_docx(merged, template='irlix-cv-an')))
        text = '\n'.join(p.text for p in final.paragraphs)
        self.assertIn('Тестовый проект 2', text)
        self.assertIn('Согласован интерфейс.', text)
        self.assertIn('ОБРАЗОВАНИЕ И ЯЗЫКИ', text)
        self.assertIn('Новые методы аналитики.', text)
        self.assertNotIn('Несуществующее профессиональное достижение', text)

    def test_unstructured_table_is_not_hallucinated_into_project(self):
        doc = Document()
        doc.add_paragraph('Тестовый Кандидат')
        grid = doc.add_table(rows=2, cols=2)
        grid.cell(0, 0).text = 'Система'
        grid.cell(0, 1).text = 'Описание'
        grid.cell(1, 0).text = 'Система А'
        grid.cell(1, 1).text = 'Простое значение'
        b = BytesIO()
        doc.save(b)
        source = extract_text('other.docx', b.getvalue())
        self.assertNotIn('[CV_PROJECT_START]', source)
        self.assertIn('Система А', source)


if __name__ == '__main__':
    unittest.main()
