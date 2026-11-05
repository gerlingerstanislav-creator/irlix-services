"""One-column IRLIX CV AN layout, matching the approved DOCX visual reference.

This is presentation only: content must come from CanonicalCv, not from the
reference candidate. Missing fields stay missing; results are not tasks.
"""
from __future__ import annotations

import base64
from io import BytesIO
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Mm, Pt, RGBColor

from .models import CanonicalCv, ProjectItem, WorkExperienceItem

INK = RGBColor(32, 42, 47)
SECONDARY = RGBColor(90, 103, 108)
ACCENT = RGBColor(0, 143, 141)
RULE = 'DCE6E7'
FONT = 'Inter'


def _write(container, value: str, *, size=9, bold=False, color=INK,
           after=3, before=0, keep=False):
    paragraph = container.add_paragraph()
    fmt = paragraph.paragraph_format
    fmt.space_before = Pt(before)
    fmt.space_after = Pt(after)
    fmt.line_spacing = 1.15
    fmt.keep_with_next = keep
    run = paragraph.add_run(value)
    run.font.name = FONT
    run.font.size = Pt(size)
    run.font.bold = bold
    run.font.color.rgb = color
    run._element.get_or_add_rPr().rFonts.set(qn('w:eastAsia'), FONT)
    return paragraph


def _heading(document, label: str):
    paragraph = _write(document, label.upper(), size=12, bold=True,
                       color=ACCENT, after=8, before=16, keep=True)
    properties = paragraph._p.get_or_add_pPr()
    borders = OxmlElement('w:pBdr')
    bottom = OxmlElement('w:bottom')
    for key, value in [('val', 'single'), ('sz', '5'),
                       ('space', '3'), ('color', RULE)]:
        bottom.set(qn(f'w:{key}'), value)
    borders.append(bottom)
    properties.append(borders)


def _bullets(document, values: list[str]):
    for value in values:
        if not value.strip():
            continue
        paragraph = _write(document, '', after=2.25)
        paragraph.paragraph_format.line_spacing = 1.13
        paragraph.paragraph_format.left_indent = Mm(5.5)
        paragraph.paragraph_format.first_line_indent = Mm(-3.3)
        bullet = paragraph.add_run('•  ')
        bullet.font.name = FONT
        bullet.font.size = Pt(9)
        bullet.font.color.rgb = ACCENT
        text = paragraph.add_run(value.strip())
        text.font.name = FONT
        text.font.size = Pt(9)
        text.font.color.rgb = INK


def _group(document, label: str, values: list[str]):
    if values:
        _write(document, label, size=9.5, bold=True, before=8,
               after=3, keep=True)
        _bullets(document, values)


def _project_label(document, label: str):
    _write(document, label, size=9, bold=True, color=ACCENT,
           before=4, after=1.5, keep=True)


def _result(document, values: list[str]):
    if values:
        _project_label(document, 'Результат')
        for value in values:
            if value.strip():
                _write(document, value.strip(), after=3)


def _project(document, item: ProjectItem):
    if item.name:
        _write(document, item.name, size=11, bold=True,
               before=13, after=3, keep=True)
    context = [value for value in [item.role, item.dates] if value]
    if item.team:
        context.append('Команда: ' + item.team)
    if item.technologies:
        context.append('Технологии: ' + ', '.join(item.technologies))
    if context:
        _write(document, ' | '.join(context), size=9,
               color=SECONDARY, after=4, keep=True)
    if item.description:
        _project_label(document, 'Описание проекта')
        _write(document, item.description)
    if item.responsibilities:
        _project_label(document, 'Выполняемые задачи')
        _bullets(document, item.responsibilities)
    _result(document, item.results)


def _work(document, item: WorkExperienceItem):
    title = item.company or item.role
    if title:
        _write(document, title, size=11, bold=True,
               before=13, after=3, keep=True)
    context = [value for value in [item.role if item.company else None, item.dates] if value]
    if item.technologies:
        context.append('Технологии: ' + ', '.join(item.technologies))
    if context:
        _write(document, ' | '.join(context), size=9,
               color=SECONDARY, after=4, keep=True)
    if item.description:
        _project_label(document, 'Описание проекта')
        _write(document, item.description)
    if item.responsibilities:
        _project_label(document, 'Выполняемые задачи')
        _bullets(document, item.responsibilities)
    _result(document, item.achievements)


def _brand_logo(section):
    """Load the isolated brand-only asset, never a candidate CV."""
    asset = Path(__file__).parent / 'assets' / 'irlix_logo.b64'
    if not asset.is_file():
        raise RuntimeError('Irlix CV AN brand asset is missing')
    logo_bytes = base64.b64decode(asset.read_text(encoding='ascii'), validate=False)
    header = section.header.paragraphs[0]
    header.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    header.paragraph_format.space_after = Pt(0)
    header.add_run().add_picture(BytesIO(logo_bytes), width=Mm(15.7))


def render_docx_an(cv: CanonicalCv) -> bytes:
    """Render only canonical facts with the approved AN styling."""
    document = Document()
    section = document.sections[0]
    section.page_width = Mm(210)
    section.page_height = Mm(297)
    section.top_margin = Mm(17.5)
    section.bottom_margin = Mm(15.5)
    section.left_margin = Mm(21.5)
    section.right_margin = Mm(21.5)
    section.header_distance = Mm(6.5)
    section.footer_distance = Mm(8)
    _brand_logo(section)

    normal = document.styles['Normal']
    normal.font.name = FONT
    normal.font.size = Pt(9)
    normal.font.color.rgb = INK
    normal.paragraph_format.space_after = Pt(3)
    normal.paragraph_format.line_spacing = 1.15

    name = cv.full_name or 'CV'
    footer = section.footer.paragraphs[0]
    footer.alignment = WD_ALIGN_PARAGRAPH.RIGHT
    footer_text = ' · '.join(value for value in ['CV', name, cv.target_role] if value)
    footer_run = footer.add_run(footer_text)
    footer_run.font.name = FONT
    footer_run.font.size = Pt(7)
    footer_run.font.color.rgb = SECONDARY

    _write(document, name, size=25, bold=True, after=0, keep=True)
    if cv.target_role:
        _write(document, cv.target_role, size=12, bold=True,
               color=ACCENT, after=5, keep=True)
    profile_exp = cv.experience.role or cv.experience.commercial
    profile_line = []
    if profile_exp:
        profile_line.append('Опыт работы по профилю: ' + profile_exp)
    if cv.projects:
        profile_line.append(f'{len(cv.projects)} проектов')
    if profile_line:
        _write(document, ' • '.join(profile_line), size=9,
               color=SECONDARY, after=8)

    if cv.summary:
        _heading(document, 'О себе')
        _write(document, cv.summary, after=7)

    if cv.skill_groups or cv.tools:
        _heading(document, 'Навыки и умения')
        for group in cv.skill_groups:
            _group(document, group.title, group.items)
        if cv.tools:
            _write(document, 'Используемые инструменты', size=9.5,
                   bold=True, before=8, after=3, keep=True)
            _write(document, ' · '.join(cv.tools), after=6)

    if cv.education or cv.languages:
        _heading(document, 'Образование и языки')
        for item in cv.education:
            details = [v for v in [item.institution, item.degree, item.specialty, item.status] if v]
            details.extend(item.details)
            if details:
                _write(document, 'Образование: ' + '; '.join(details))
        for item in cv.languages:
            value = item.language + (': ' + item.level if item.level else '')
            _write(document, value)

    contacts = cv.contacts
    pairs = [('Телефон', contacts.phone), ('Email', contacts.email),
             ('Telegram', contacts.telegram), ('Город', contacts.location),
             ('Формат работы', contacts.work_format)]
    contact_lines = [f'{label}: {value}' for label, value in pairs if value]
    contact_lines.extend(contacts.links)
    if contact_lines:
        _heading(document, 'Контакты и условия')
        for line in contact_lines:
            _write(document, line)

    if cv.projects or cv.work_experience:
        # Unlike the legacy template, the AN reference flows directly from
        # education into project experience on the same page.
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
            _write(document, item.answer)
        for item in cv.extra_sections:
            if item.title:
                _write(document, item.title, bold=True, before=6, keep=True)
            _bullets(document, item.items)

    output = BytesIO()
    document.save(output)
    return output.getvalue()
