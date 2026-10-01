"""Offline CV regression check; no Keycloak, model or real CV required."""
import unittest

from .models import CanonicalCv, ExperienceSummary, SkillGroup, ProjectItem
from .renderer import render_docx, render_pdf


def _run_structural_regressions():
    suite = unittest.defaultTestLoader.discover('tests', pattern='test_*.py')
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    if not result.wasSuccessful():
        raise RuntimeError('Structural CV regression tests failed')


def main():
    _run_structural_regressions()

    cv = CanonicalCv(
        full_name='Тестовый специалист',
        target_role='Системный аналитик',
        experience=ExperienceSummary(commercial='5 лет', role='5 лет'),
        skill_groups=[SkillGroup(title='Анализ', items=['Сбор требований', 'BPMN'])],
        tools=['Jira', 'Confluence'],
        projects=[ProjectItem(role='Системный аналитик', dates='2024–2026', name='Тестовый проект',
                              description='Описание', responsibilities=['Сбор требований'])],
    )
    docx, pdf = render_docx(cv), render_pdf(cv)
    assert docx.startswith(b'PK') and len(docx) > 1000
    assert pdf.startswith(b'%PDF') and len(pdf) > 1000
    print(f'DOCX={len(docx)} PDF={len(pdf)}')


if __name__ == '__main__':
    main()
