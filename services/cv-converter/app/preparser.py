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
_PROFILE_LABELS = ('формат', 'telegram', 'email', 'телефон', 'phone', 'английский', 'english', 'location', 'локация')


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


def _simple_profile_value(value: str) -> bool:
    lower = value.casefold()
    return (
        bool(value)
        and ':' not in value
        and '@' not in value
        and not any(ch.isdigit() for ch in value)
        and not lower.startswith(_PROFILE_LABELS)
        and len(value) <= 100
    )


def _parse_identity(blocks: list[list[str]]) -> tuple[str | None, str | None]:
    if not blocks:
        return None, None

    target_role: str | None = None
    for block in blocks[:4]:
        if len(block) != 1:
            continue
        value = block[0]
        if _simple_profile_value(value) and 1 <= len(value.split()) <= 8:
            target_role = value
            break

    full_name: str | None = None
    first = blocks[0]
    if target_role:
        combined = ' '.join(first)
        if combined.casefold().startswith(target_role.casefold()):
            candidate = combined[len(target_role):].strip(' -—,')
            if 1 <= len(candidate.split()) <= 5:
                full_name = candidate
    if not full_name and len(first) <= 2:
        candidate = ' '.join(first)
        if _simple_profile_value(candidate) and 1 <= len(candidate.split()) <= 5:
            full_name = candidate

    return full_name, target_role


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
        # Block-preserving PDF extraction makes title/description pairs explicit.
        # We only accept a short single-line title followed by a substantial prose block.
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

    full_name, target_role = _parse_identity(profile_blocks)
    contacts, summary, languages = _parse_profile(profile_blocks)
    work_experience = _parse_work_experience(experience_blocks)
    projects = _parse_projects(project_blocks)
    tools = _parse_skills(skill_blocks)

    seed = CanonicalCv(
        full_name=full_name,
        target_role=target_role,
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


def _work_key(item: WorkExperienceItem) -> tuple[str, str]:
    return (_key(item.company or ''), _key(item.role or ''))


def _project_key(item: ProjectItem) -> str:
    return _key(item.name or '')


def _merge_work(seed_item: WorkExperienceItem, llm_item: WorkExperienceItem | None) -> WorkExperienceItem:
    if llm_item is None:
        return seed_item
    return WorkExperienceItem(
        company=seed_item.company or llm_item.company,
        role=seed_item.role or llm_item.role,
        dates=seed_item.dates or llm_item.dates,
        description=llm_item.description or seed_item.description,
        responsibilities=seed_item.responsibilities or llm_item.responsibilities,
        achievements=llm_item.achievements,
        technologies=llm_item.technologies,
    )


def _merge_project(seed_item: ProjectItem, llm_item: ProjectItem | None) -> ProjectItem:
    if llm_item is None:
        return seed_item
    return ProjectItem(
        role=llm_item.role or seed_item.role,
        dates=llm_item.dates or seed_item.dates,
        team=llm_item.team or seed_item.team,
        technologies=llm_item.technologies or seed_item.technologies,
        name=seed_item.name or llm_item.name,
        description=seed_item.description or llm_item.description,
        responsibilities=llm_item.responsibilities or seed_item.responsibilities,
    )


def merge_preparsed(cv: CanonicalCv, parsed: PreparsedCv) -> CanonicalCv:
    seed = parsed.seed

    if not cv.full_name and seed.full_name:
        cv.full_name = seed.full_name
    if not cv.target_role and seed.target_role:
        cv.target_role = seed.target_role

    # Explicit source facts win over generated normalization for profile fields.
    if seed.contacts.email:
        cv.contacts.email = seed.contacts.email
    if seed.contacts.phone:
        cv.contacts.phone = seed.contacts.phone
    if seed.contacts.telegram:
        cv.contacts.telegram = seed.contacts.telegram
    if seed.contacts.work_format:
        cv.contacts.work_format = seed.contacts.work_format
    if seed.summary:
        cv.summary = seed.summary
    if seed.languages:
        cv.languages = seed.languages

    if seed.work_experience:
        llm_by_key = {_work_key(item): item for item in cv.work_experience if any(_work_key(item))}
        merged_work = [_merge_work(item, llm_by_key.get(_work_key(item))) for item in seed.work_experience]
        if len(cv.work_experience) != len(merged_work) or any(_work_key(item) not in llm_by_key for item in seed.work_experience):
            cv.warnings.append(
                f'work_experience нормализован по pre-parser: зафиксировано {len(merged_work)} исходных блоков'
            )
        cv.work_experience = merged_work

    if seed.projects:
        llm_by_name = {_project_key(item): item for item in cv.projects if _project_key(item)}
        merged_projects = [_merge_project(item, llm_by_name.get(_project_key(item))) for item in seed.projects]
        if len(cv.projects) != len(merged_projects) or any(_project_key(item) not in llm_by_name for item in seed.projects):
            cv.warnings.append(
                f'projects нормализованы по pre-parser: зафиксировано {len(merged_projects)} исходных блоков'
            )
        cv.projects = merged_projects

    if not cv.tools and seed.tools:
        cv.tools = seed.tools

    return cv
