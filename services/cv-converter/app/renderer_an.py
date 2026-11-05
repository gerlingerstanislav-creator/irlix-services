"""Minimalist single-column IRLIX CV AN renderer.

The template is a presentation of CanonicalCv, never an extraction pipeline.
It omits absent facts and preserves explicit project outcomes separately from duties.
"""
from __future__ import annotations

from io import BytesIO

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Mm, Pt, RGBColor

from .models import CanonicalCv, ProjectItem, WorkExperienceItem


INK = RGBColor(34, 40, 49)
SECONDARY = RGBColor(92, 99, 109)
RULE = 'DDE2E8'
FONT = 'Arial'


def _write(container, value: str, *, size=9, bold=False, color=INK, after=3, before=0, keep=False):
    paragraph = container.add_paragraph()
    paragraph.paragraph_format.space_before = Pt(before)
    paragraph.paragraph_format.space_after = Pt(after)
    paragraph.paragraph_format.line_spacing = 1.12
    paragraph.paragraph_format.keep_with_next = keep
    run = paragraph.add_run(value)
    run.font.name = FONT
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.color.rgb = color
    run._element.get_or_add_rPr().rFonts.set(qn('w:eastAsia'), FONT)
    return paragraph


def _heading(document, label: str):
    paragraph = _write(document, label.upper(), size=11, bold=True, after=9, before=13, keep=True)
    properties = paragraph._p.get_or_add_pPr()
    borders = OxmlElement('w:pBdr')
    bottom = OxmlElement('w:bottom')
    for key, value in [('val', 'single'), ('sz', '6'), ('space', '3'), ('color', RULE)]:
        bottom.set(qn(f'w:{key}'), value)
    borders.append(bottom)
    properties.append(borders)


def _bullets(document, values: list[str]):
    for value in values:
        if not value.strip():
            continue
        p = _write(document, value.strip(), size=9, after=3)
        p.paragraph_format.left_indent = Mm(5)
        p.paragraph_format.first_line_indent = Mm(-3)
        p.runs[0].text = '• ' + value.strip()


def _label_and_values(document, label: str, values: list[str]):
    if values:
        _write(document, label, size=9.5, bold=True, before=7, after=3, keep=True)
        _bullets(document, values)


def _project(document, item: ProjectItem):
    if item.name:
        _write(document, item.name, size=11, bold=True, before=12, after=4, keep=True)
    context = [value for value in [item.role, item.dates] if value]
    if item.team:
        context.append('Команда: ' + item.team)
    if item.technologies:
        context.append('Технологии: ' + ', '.join(item.technologies))
    if context:
        _write(document, ' | '.join(context), size=8.5, color=SECONDARY, after=7, keep=True)
    if item.description:
        _write(document, 'Описание проекта', size=9.5, bold=True, after=2, keep=True)
        _write(document, item.description, after=6)
    _label_and_values(document, 'Выполняемые задачи', item.responsibilities)
    _label_and_values(document, 'Результат', item.results)


def _work(document, item: WorkExperienceItem):
    title = item.company or item.role
    if title:
        _write(document, title, size=11, bold=True, before=12, after=4, keep=True)
    detail = [value for value in [item.role if item.company else None, item.dates] if value]
    if item.technologies:
        detail.append('Технологии: ' + ', '.join(item.technologies))
    if detail:
        _write(document, ' | '.join(detail), size=8.5, color=SECONDARY, after=7, keep=True)
    if item.description:
        _write(document, 'Описание', size=9.5, bold=True, after=2, keep=True)
        _write(document, item.description, after=6)
    _label_and_values(document, 'Выполняемые задачи', item.responsibilities)
    _label_and_values(document, 'Результат', item.achievements)


def render_docx_an(cv: CanonicalCv) -> bytes:
    """Build IRLIX CV AN from canonical facts; output is intentionally ephemeral."""
    document = Document()
    section = document.sections[0]
    section.page_width = Mm(210)
    section.page_height = Mm(297)
    section.top_margin = Mm(17)
    section.bottom_margin = Mm(19)
    section.left_margin = Mm(19)
    section.right_margin = Mm(19)

    normal = document.styles['Normal']
    normal.font.name = FONT
    normal.font.size = Pt(9)
    normal.font.color.rgb = INK

    # Footer intentionally contains only facts already present in the source CV.
    footer = section.footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    name = cv.full_name or 'CV'
    footer_text = ' · '.join(value for value in ['CV', name, cv.target_role] if value)
    footer_run = footer.add_run(footer_text)
    footer_run.font.name = FONT
    footer_run.font.size = Pt(8)
    footer_run.font.color.rgb = SECONDARY

    _write(document, name, size=27, bold=True, after=2, keep=True)
    if cv.target_role:
        _write(document, cv.target_role, size=13, color=SECONDARY, after=7, keep=True)
    profile_exp = cv.experience.role or cv.experience.commercial
    profile_line = []
    if profile_exp:
        profile_line.append('Опыт работы по профилю: ' + profile_exp)
    if cv.projects:
        profile_line.append(f'{len(cv.projects)} проектов')
    if profile_line:
        _write(document, ' • '.join(profile_line), size=9.5, after=11)

    if cv.summary:
        _heading(document, 'О себе')
        _write(document, cv.summary, size=9.5, after=7)

    if cv.skill_groups or cv.tools:
        _heading(document, 'Навыки и умения')
        for group in cv.skill_groups:
            _label_and_values(document, group.title, group.items)
        if cv.tools:
            _write(document, 'Используемые инструменты', size=9.5, bold=True, before=9, after=3, keep=True)
            _write(document, ' · '.join(cv.tools), after=6)

    if cv.education or cv.languages:
        _heading(document, 'Образование и языки')
        for item in cv.education:
            details = [value for value in [item.institution, item.degree, item.specialty, item.status] if value]
            details.extend(item.details)
            if details:
                _write(document, 'Образование: ' + '; '.join(details), after=5)
        for item in cv.languages:
            value = item.language + (': ' + item.level if item.level else '')
            _write(document, value, after=5)

    contacts = cv.contacts
    contact_values = [
        ('Телефон', contacts.phone), ('Email', contacts.email),
        ('Telegram', contacts.telegram), ('Город', contacts.location),
        ('Формат работы', contacts.work_format),
    ]
    contact_lines = [f'{label}: {value}' for label, value in contact_values if value]
    contact_lines.extend(contacts.links)
    if contact_lines:
        _heading(document, 'Контакты и условия')
        for line in contact_lines:
            _write(document, line)

    if cv.projects or cv.work_experience:
        document.add_page_break()
        _heading(document, 'Опыт работы' + (' — ' + profile_exp if profile_exp else ''))
        for item in cv.work_experience:
            _work(document, item)
        for item in cv.projects:
            _project(document, item)

    if cv.additional or cv.extra_sections:
        _heading(document, 'Дополнительная информация')
        for item in cv.additional:
            if item.question:
                _write(document, item.question, bold=True, before=6, keep=True)
            _write(document, item.answer, after=6)
        for item in cv.extra_sections:
            if item.title:
                _write(document, item.title, bold=True, before=6, keep=True)
            _bullets(document, item.items)

    output = BytesIO()
    document.save(output)
    return output.getvalue()
