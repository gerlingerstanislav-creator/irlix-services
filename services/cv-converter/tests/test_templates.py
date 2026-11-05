"""Synthetic regression tests for template routing and AN document content."""
import shutil
import unittest
from io import BytesIO

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
        self.assertIn('Проекты', text)
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
