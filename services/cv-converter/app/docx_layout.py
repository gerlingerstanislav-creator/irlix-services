"""Read CV DOCX in document order and preserve two-column project records.

Document content is emitted as text only and never stored. Structural markers
are internal to extraction and must not appear in user-facing exports.
"""
from __future__ import annotations

import re
from io import BytesIO

from docx import Document
from docx.oxml.ns import qn
from docx.table import Table
from docx.text.paragraph import Paragraph

_DATE_RE = re.compile(r'(?<!\d)(?:0?[1-9]|1[0-2])[./](?:19|20)\d{2}(?!\d)')
_DESCRIPTION = {'описание проекта', 'описание'}
_TASKS = {'выполняемые задачи', 'выполняемые задачи (что и как делалось)', 'обязанности', 'задачи'}
_RESULTS = {'результат', 'результаты', 'достижения'}


def _lines(text: str) -> list[str]:
    """Split manually embedded line breaks without cutting a single duty."""
    return [re.sub(r'\s+', ' ', s.replace('\u00ad', '')).strip() for s in text.splitlines() if s.strip()]


def _cell_lines(cell) -> list[str]:
    result = []
    for paragraph in cell.paragraphs:
        result.extend(_lines(paragraph.text))
    return result


def _parse_project_table(table: Table) -> list[str] | None:
    if len(table.columns) != 2:
        return None
    # DOCX writers frequently duplicate vertically merged rows in the high-level
    # API. Read one logical table record, never every repeated continuation cell.
    candidates = []
    seen = set()
    for row in table.rows:
        left, right = (_cell_lines(cell) for cell in row.cells)
        key = (tuple(left), tuple(right))
        if key in seen:
            continue
        seen.add(key)
        if left and right:
            candidates.append((left, right))
    if len(candidates) != 1:
        return None
    left, right = candidates[0]
    heading_positions = [i for i, line in enumerate(right) if line.strip().casefold() in _DESCRIPTION]
    date_index = next((i for i, line in enumerate(left[:5]) if _DATE_RE.search(line)), None)
    if date_index is None or not heading_positions or heading_positions[0] == 0:
        return None

    output = ['[CV_PROJECT_START]']
    names = right[:heading_positions[0]]
    output.append('[NAME] ' + ' — '.join(names))
    if date_index:
        output.append('[ROLE] ' + ' '.join(left[:date_index]))
    output.append('[DATES] ' + left[date_index])
    for value in left[date_index + 1:]:
        low = value.casefold()
        if low.startswith(('команда ', 'команда:', 'team:', 'team ')):
            output.append('[TEAM] ' + re.sub(r'^(?:команда|team)\s*:?\s*', '', value, flags=re.I))
        else:
            output.append('[TECH] ' + value)

    mode = 'description'
    for line in right[heading_positions[0] + 1:]:
        key = line.casefold().strip(' :')
        if key in _DESCRIPTION:
            mode = 'description'
        elif key in _TASKS or key.startswith('выполняемые задачи ('):
            mode = 'task'
        elif key in _RESULTS:
            mode = 'result'
        else:
            output.append(f'[{mode.upper()}] {line}')
    output.append('[CV_PROJECT_END]')
    return output


def extract_docx_layout(data: bytes) -> str:
    """Preserve paragraph/table order, and mark proven two-column project rows."""
    document = Document(BytesIO(data))
    blocks: list[str] = []
    for child in document.element.body.iterchildren():
        if child.tag == qn('w:p'):
            lines = _lines(Paragraph(child, document).text)
            if lines:
                blocks.append('\n'.join(lines))
        elif child.tag == qn('w:tbl'):
            table = Table(child, document)
            project = _parse_project_table(table)
            if project is not None:
                blocks.append('\n'.join(project))
                continue
            seen = set()
            for row in table.rows:
                cells = [tuple(_cell_lines(cell)) for cell in row.cells]
                if not any(cells) or tuple(cells) in seen:
                    continue
                seen.add(tuple(cells))
                blocks.extend('\n'.join(cell) for cell in cells if cell)
    return '\n\n'.join(blocks).strip()
