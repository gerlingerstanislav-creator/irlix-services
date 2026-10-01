from __future__ import annotations

from io import BytesIO

import fitz
from docx import Document


class UnsupportedSourceError(ValueError):
    pass


def extract_text(filename: str, data: bytes) -> str:
    name = filename.lower()
    if name.endswith('.pdf'):
        return _extract_pdf(data)
    if name.endswith('.docx'):
        return _extract_docx(data)
    if name.endswith('.doc'):
        raise UnsupportedSourceError('Legacy .doc пока не поддерживается. Сохраните документ как DOCX.')
    raise UnsupportedSourceError('Поддерживаются PDF и DOCX.')


def _extract_pdf(data: bytes) -> str:
    document = fitz.open(stream=data, filetype='pdf')
    pages = [page.get_text('text').strip() for page in document]
    text = '\n\n'.join(page for page in pages if page).strip()
    if not text:
        raise UnsupportedSourceError('В PDF не найден текстовый слой. Для этого документа потребуется OCR.')
    return text


def _extract_docx(data: bytes) -> str:
    document = Document(BytesIO(data))
    parts: list[str] = []
    for paragraph in document.paragraphs:
        value = paragraph.text.strip()
        if value:
            parts.append(value)
    for table in document.tables:
        for row in table.rows:
            cells = [cell.text.strip() for cell in row.cells if cell.text.strip()]
            if cells:
                parts.append(' | '.join(cells))
    text = '\n'.join(parts).strip()
    if not text:
        raise UnsupportedSourceError('DOCX не содержит извлекаемого текста.')
    return text
