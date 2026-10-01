from __future__ import annotations

import re
from dataclasses import dataclass, field

from .models import CanonicalCv, ContactInfo, LanguageItem, ProjectItem, WorkExperienceItem


_ROLE_DATES_RE = re.compile(r'^(?P<role>.+?)\s*\((?P<dates>[^()]*(?:19|20)\d{2}[^()]*)\)\s*$')
_EMAIL_RE = re.compile(r'(?i)\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b')
_PHONE_RE = re.compile(r'(?<!\d)(?:\+?\d[\d\s()\-]{7,}\d)')
_TELEGRAM_RE = re.compile(r'(?i)(?:telegram|tg)\s*:\s*(@?[A-Za-z0-9_]+)')
_PAGE_FOOTER_RE = re.compile(r'^(.+?)\s+(\d{1,3})$')

_EXPERIENCE_HEADINGS = {'опыт работы', 'опыт', 'work experience', 'experience'}
_PROJECT_HEADINGS = {'проекты', 'projects', 'project experience'}
_SKILL_HEADINGS = {'skills', 'навыки', 'ключевые навыки', 'skills & tools', 'technologies'}
_STOP_HEADINGS = {
    'образование', 'education', 'языки', 'languages', 'иностранные языки',
    'дополнительная информация', 'additional information', 'сертификаты', 'certifications',
}


def _norm(value: str) -> str:
    return re.sub(r'\s+', ' ', value.replace('\ufffe', '-').replace('\u00ad', '')).strip()


def _key(value: str) -> str:
    return _norm(value).rstrip(':').casefold()


def _split_blocks(source_text: str) -> list[list[str]]:
    blocks: list[list[str]] = []
    for raw_block in re.split(r'\n\s*\n+', source_text):
        lines = [_norm(line) for line in raw_block.splitlines() if _norm(line)]
        if lines:
            blocks.append(lines)
    return blocks


def _looks_like_footer(block: list[str], header_tokens: set[str]) -> bool:
    if len(block) > 2:
        return False
    joined = ' '.join(block)
    match = _PAGE_FOOTER_RE.match(joined)
    if not match:
        return False
    words = {word.casefold() for word in re.findall(r'[A-Za-zА-Яа-яЁё]+', match.group(1))}
    return len(words & header_tokens) >= 2


def _heading_index(blocks: list[list[str]], variants: set[str]) -> int | None:
    for index, block in enumerate(blocks):
        if len(block) == 1 and _key(block[0]) in variants:
            return index
    return None


@dataclass
class PreparsedCv:
    clean_text: str
    profile_text: str
    experience_text: str
    projects_text: str
    skills_text: str
    seed: CanonicalCv
    expected_work_experience: int = 0
    expected_projects: int = 0
    notes: list[str] = field(default_factory=list)

    def prompt_text(self) -> str:
        parts = [
            '[PROFILE]', self.profile_text or '(empty)',
            '[WORK_EXPERIENCE]', self.experience_text or '(empty)',
            '[PROJECTS]', self.projects_text or '(empty)',
            '[SKILLS]', self.skills_text or '(empty)',
        ]
        return '\n'.join(parts)


def _parse_profile(blocks: list[list[str]]) -> tuple[ContactInfo, str | None, list[LanguageItem]]:
    contacts = ContactInfo()
    summary_parts: list[str] = []
    languages: list[LanguageItem] = []

    for block in blocks:
        text = ' '.join(block)
        key = _key(text)
        email = _EMAIL_RE.search(text)
        phone = _PHONE_RE.search(text)
        telegram = _TELEGRAM_RE.search(text)
        if email:
            contacts.email = email.group(0)
            continue
        if telegram:
            value = telegram.group(1)
            contacts.telegram = value if value.startswith('@') else f'@{value}'
            continue
        if phone and any(marker in key for marker in ('телефон', 'phone', 'моб')):
            contacts.phone = phone.group(0)
            continue
        if key.startswith(('формат:', 'формат работы:', 'work format:')):
            contacts.work_format = text.split(':', 1)[1].strip() if ':' in text else text
            continue
        if key.startswith(('английский:', 'english:')):
            left, _, right = text.partition(':')
            languages.append(LanguageItem(language=left.strip(), level=right.strip() or None))
            continue
        if len(text) >= 120 or len(block) >= 3:
            summary_parts.append(text)

    return contacts, ' '.join(summary_parts).strip() or None, languages


def _parse_work_experience(blocks: list[list[str]]) -> list[WorkExperienceItem]:
    result: list[WorkExperienceItem] = []
    current: WorkExperienceItem | None = None

    for block in blocks:
        role_line_index = next((i for i, line in enumerate(block) if _ROLE_DATES_RE.match(line)), None)
        if role_line_index is not None:
            match = _ROLE_DATES_RE.match(block[role_line_index])
            assert match is not None
            company_lines = block[:role_line_index]
            company = ' '.join(company_lines).strip() or None
            current = WorkExperienceItem(
                company=company,
                role=match.group('role').strip(),
                dates=match.group('dates').strip(),
            )
            result.append(current)
            trailing = block[role_line_index + 1:]
            if trailing:
                current.responsibilities.append(' '.join(trailing))
            continue
        if current is not None:
            text = ' '.join(block).strip(' •-')
            if text:
                current.responsibilities.append(text)

    return result


def _parse_projects(blocks: list[list[str]]) -> list[ProjectItem]:
    result: list[ProjectItem] = []
    index = 0
    while index < len(blocks):
        block = blocks[index]
        # In block-preserving extraction, a project title is normally its own short block,
        # followed by a prose description block. This is intentionally conservative.
        if len(block) == 1 and len(block[0]) <= 120 and index + 1 < len(blocks):
            next_block = blocks[index + 1]
            description = ' '.join(next_block)
            if len(description) >= 60:
                result.append(ProjectItem(name=block[0], description=description))
                index += 2
                continue
        index += 1
    return result


def _parse_skills(blocks: list[list[str]]) -> list[str]:
    values: list[str] = []
    seen: set[str] = set()
    for block in blocks:
        for line in block:
            value = line.strip(' •-')
            key = value.casefold()
            if not value or key in _SKILL_HEADINGS or key in seen:
                continue
            if len(value) > 80:
                continue
            seen.add(key)
            values.append(value)
    return values


def preparse_cv(source_text: str) -> PreparsedCv:
    blocks = _split_blocks(source_text)
    first_words = {word.casefold() for block in blocks[:3] for word in re.findall(r'[A-Za-zА-Яа-яЁё]+', ' '.join(block))}
    blocks = [block for block in blocks if not _looks_like_footer(block, first_words)]

    exp_idx = _heading_index(blocks, _EXPERIENCE_HEADINGS)
    projects_idx = _heading_index(blocks, _PROJECT_HEADINGS)
    skills_idx = _heading_index(blocks, _SKILL_HEADINGS)

    boundaries = [idx for idx in (exp_idx, projects_idx, skills_idx) if idx is not None]
    profile_end = min(boundaries) if boundaries else len(blocks)
    profile_blocks = blocks[:profile_end]

    exp_end_candidates = [idx for idx in (projects_idx, skills_idx) if idx is not None and exp_idx is not None and idx > exp_idx]
    exp_end = min(exp_end_candidates) if exp_end_candidates else len(blocks)
    experience_blocks = blocks[exp_idx + 1:exp_end] if exp_idx is not None else []

    project_end_candidates = [idx for idx in (skills_idx,) if idx is not None and projects_idx is not None and idx > projects_idx]
    project_end = min(project_end_candidates) if project_end_candidates else len(blocks)
    project_blocks = blocks[projects_idx + 1:project_end] if projects_idx is not None else []

    skills_end = len(blocks)
    if skills_idx is not None:
        for idx in range(skills_idx + 1, len(blocks)):
            if len(blocks[idx]) == 1 and _key(blocks[idx][0]) in _STOP_HEADINGS:
                skills_end = idx
                break
    skill_blocks = blocks[skills_idx + 1:skills_end] if skills_idx is not None else []

    contacts, summary, languages = _parse_profile(profile_blocks)
    work_experience = _parse_work_experience(experience_blocks)
    projects = _parse_projects(project_blocks)
    tools = _parse_skills(skill_blocks)

    seed = CanonicalCv(
        contacts=contacts,
        summary=summary,
        languages=languages,
        work_experience=work_experience,
        projects=projects,
        tools=tools,
    )

    notes: list[str] = []
    if work_experience:
        notes.append(f'pre-parser found {len(work_experience)} work experience entries')
    if projects:
        notes.append(f'pre-parser found {len(projects)} projects')

    return PreparsedCv(
        clean_text='\n\n'.join('\n'.join(block) for block in blocks),
        profile_text='\n\n'.join('\n'.join(block) for block in profile_blocks),
        experience_text='\n\n'.join('\n'.join(block) for block in experience_blocks),
        projects_text='\n\n'.join('\n'.join(block) for block in project_blocks),
        skills_text='\n\n'.join('\n'.join(block) for block in skill_blocks),
        seed=seed,
        expected_work_experience=len(work_experience),
        expected_projects=len(projects),
        notes=notes,
    )


def merge_preparsed(cv: CanonicalCv, parsed: PreparsedCv) -> CanonicalCv:
    seed = parsed.seed

    if not cv.contacts.email and seed.contacts.email:
        cv.contacts.email = seed.contacts.email
    if not cv.contacts.phone and seed.contacts.phone:
        cv.contacts.phone = seed.contacts.phone
    if not cv.contacts.telegram and seed.contacts.telegram:
        cv.contacts.telegram = seed.contacts.telegram
    if not cv.contacts.work_format and seed.contacts.work_format:
        cv.contacts.work_format = seed.contacts.work_format
    if not cv.summary and seed.summary:
        cv.summary = seed.summary
    if not cv.languages and seed.languages:
        cv.languages = seed.languages

    if len(cv.work_experience) < parsed.expected_work_experience:
        cv.work_experience = seed.work_experience
        cv.warnings.append(
            f'work_experience восстановлен pre-parser: ожидалось {parsed.expected_work_experience}, LLM вернула меньше'
        )
    if len(cv.projects) < parsed.expected_projects:
        cv.projects = seed.projects
        cv.warnings.append(
            f'projects восстановлены pre-parser: ожидалось {parsed.expected_projects}, LLM вернула меньше'
        )
    if not cv.tools and seed.tools:
        cv.tools = seed.tools

    return cv
