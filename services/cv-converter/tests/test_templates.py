"""Synthetic regression tests for template routing and AN document content."""
import shutil
import unittest
from io import BytesIO
from zipfile import ZipFile

from docx import Document

from app.models import (
    CanonicalCv, ContactInfo, ExperienceSummary, ProjectItem, SkillGroup,
)
from app.renderer import render_docx, render_pdf


def sample_cv():
    return CanonicalCv(
        full_name='Тестовый Кандидат',
        target_role='Системный аналитик',
        summary='Анализ требований и проектирование интеграций.',
        experience=ExperienceSummary(role='5 лет'),
        contacts=ContactInfo(email='example@example.test'),
        skill_groups=[SkillGroup(title='Анализ', items=['BPMN', 'User Stories'])],
        tools=['Jira', 'Confluence'],
        projects=[
            ProjectItem(
                name='Синтетический проект',
                role='Аналитик',
                dates='2024–2026',
                description='Разработка тестовой системы.',
                responsibilities=['Сбор требований'],
                results=['Подготовлена согласованная спецификация'],
            ),
        ],
    )


def paragraphs(docx_bytes):
    document = Document(BytesIO(docx_bytes))
    return '\n'.join(p.text for p in document.paragraphs)


class CvTemplateTest(unittest.TestCase):
    def test_legacy_template_keeps_existing_layout(self):
        text = paragraphs(render_docx(sample_cv()))
        self.assertIn('Навыки и умения:', text)
        self.assertIn('Опыт работы', text)
        self.assertNotIn('О СЕБЕ', text)

    def test_an_template_preserves_distinct_project_results(self):
        text = paragraphs(render_docx(sample_cv(), template='irlix-cv-an'))
        for expected in [
            'О СЕБЕ', 'НАВЫКИ И УМЕНИЯ', 'ОБРАЗОВАНИЕ И ЯЗЫКИ',
        ]:
            if expected == 'ОБРАЗОВАНИЕ И ЯЗЫКИ':
                self.assertNotIn(expected, text)  # No fabricated empty sections.
            else:
                self.assertIn(expected, text)
        self.assertIn('Синтетический проект', text)
        self.assertIn('Выполняемые задачи', text)
        self.assertIn('Сбор требований', text)
        self.assertIn('Результат', text)
        self.assertIn('Подготовлена согласованная спецификация', text)
        self.assertIn('Контакты и условия'.upper(), text)

    def test_an_footer_is_generated_from_candidate_identity(self):
        document = Document(BytesIO(render_docx(sample_cv(), template='irlix-cv-an')))
        footer = document.sections[0].footer.paragraphs[0].text
        self.assertIn('Тестовый Кандидат', footer)
        self.assertIn('Системный аналитик', footer)

    def test_an_reference_layout_has_header_brand_and_no_forced_page_break(self):
        rendered = render_docx(sample_cv(), template='irlix-cv-an')
        document = Document(BytesIO(rendered))
        section = document.sections[0]
        self.assertAlmostEqual(section.left_margin.mm, 21.5, places=1)
        self.assertAlmostEqual(section.top_margin.mm, 17.5, places=1)
        self.assertIn('w:drawing', section.header.paragraphs[0]._p.xml)
        self.assertEqual(document.paragraphs[1].runs[0].font.color.rgb.__str__(), '008F8D')
        self.assertEqual(document.paragraphs[1].runs[0].font.size.pt, 12)
        with ZipFile(BytesIO(rendered)) as archive:
            xml = archive.read('word/document.xml').decode('utf-8')
            self.assertNotIn('w:type="page"', xml)

    def test_an_results_are_prose_not_task_bullets(self):
        rendered = render_docx(sample_cv(), template='irlix-cv-an')
        document = Document(BytesIO(rendered))
        paragraphs = document.paragraphs
        label_index = next(i for i, p in enumerate(paragraphs) if p.text == 'Результат')
        self.assertEqual(paragraphs[label_index + 1].text, 'Подготовлена согласованная спецификация')
        self.assertFalse(paragraphs[label_index + 1].text.startswith('•'))
        self.assertEqual(paragraphs[label_index].runs[0].font.color.rgb.__str__(), '008F8D')

    def test_an_second_sparse_cv_omits_absent_sections(self):
        sparse = CanonicalCv(full_name='Другой тестовый кандидат')
        rendered = render_docx(sparse, template='irlix-cv-an')
        text = paragraphs(rendered)
        self.assertIn('Другой тестовый кандидат', text)
        self.assertNotIn('О СЕБЕ', text)
        self.assertNotIn('ОПЫТ РАБОТЫ', text)
        self.assertNotIn('Результат', text)
        self.assertIn('w:drawing', Document(BytesIO(rendered)).sections[0].header.paragraphs[0]._p.xml)

    def test_unknown_template_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Unsupported CV template'):
            render_docx(sample_cv(), template='unknown')

    @unittest.skipUnless(shutil.which('libreoffice') or shutil.which('soffice'), 'LibreOffice required')
    def test_pdf_generated_from_selected_docx(self):
        # The PDF renderer invokes the same template-specific DOCX renderer.
        pdf = render_pdf(sample_cv(), template='irlix-cv-an')
        self.assertTrue(pdf.startswith(b'%PDF'))
        self.assertGreater(len(pdf), 1000)


if __name__ == '__main__':
    unittest.main()
