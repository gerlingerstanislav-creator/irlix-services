"""Deterministic recovery for structure already explicit in source documents.

Only parse facts present in the source. This protects long, table-based CVs from
LLM omission, truncated generation and ungrounded synthesized results.
"""
from __future__ import annotations

import re

from .models import AdditionalItem, EducationItem, ExperienceSummary, LanguageItem, ProjectItem, SkillGroup

_MARK_RE = re.compile(r'^\[(NAME|ROLE|DATES|TEAM|TECH|DESCRIPTION|TASK|RESULT)\]\s*(.*)$')
_DATE_RE = re.compile(r'\b(?:0?[1-9]|1[0-2])[./](?:19|20)\d{2}\b')
_PROJECTS_RE = re.compile(r'(\d+)\s+проект\w*', re.I)
_SKILL_SKIP = {'ключевые навыки', 'дополнительные навыки'}


def _key(text: str) -> str:
    return re.sub(r'\s+', ' ', text).strip(' :').casefold()


def _slice_section(lines: list[str], *, start: set[str], ends: set[str]) -> list[str]:
    begin = next((i for i, line in enumerate(lines) if _key(line) in start), None)
    if begin is None:
        return []
    finish = next((i for i in range(begin + 1, len(lines))
                   if _key(lines[i]) in ends
                   or ('опыт работы' in ends and _key(lines[i]).startswith('опыт работы'))), len(lines))
    return lines[begin + 1:finish]


def projects_from_markers(source_text: str) -> list[ProjectItem]:
    projects: list[ProjectItem] = []
    fields: dict[str, list[str]] | None = None
    for line in source_text.splitlines():
        line = line.strip()
        if line == '[CV_PROJECT_START]':
            fields = {}
        elif line == '[CV_PROJECT_END]':
            if fields and fields.get('NAME'):
                def value(key):
                    return ' '.join(fields.get(key, [])).strip() or None
                projects.append(ProjectItem(
                    name=value('NAME'), role=value('ROLE'), dates=value('DATES'),
                    team=value('TEAM'),
                    technologies=[x.strip() for chunk in fields.get('TECH', [])
                                  for x in chunk.split(',') if x.strip()],
                    description=value('DESCRIPTION'),
                    responsibilities=fields.get('TASK', []),
                    results=fields.get('RESULT', []),
                ))
            fields = None
        elif fields is not None:
            match = _MARK_RE.fullmatch(line)
            if match and match.group(2).strip():
                fields.setdefault(match.group(1), []).append(match.group(2).strip())
    return projects


def _skills(lines: list[str]) -> tuple[list[SkillGroup], list[str]]:
    groups: list[SkillGroup] = []
    tools: list[str] = []
    current: SkillGroup | None = None
    mode = 'skills'
    for line in lines:
        value = line.strip(' \u2022')
        key = _key(value)
        if not value or key in _SKILL_SKIP:
            continue
        if key == 'используемые инструменты':
            mode = 'tools'
            current = None
            continue
        if mode == 'tools':
            if value not in tools:
                tools.append(value)
            continue
        if value.endswith(':') and len(value) <= 110:
            current = SkillGroup(title=value[:-1].strip(), items=[])
            groups.append(current)
            continue
        if current is None:
            current = SkillGroup(title='Ключевые навыки', items=[])
            groups.append(current)
        if value.startswith('(') and current.items:
            # Word CVs often place clarification in a separate paragraph/column.
            current.items[-1] += ' ' + value
        elif value not in current.items:
            current.items.append(value)
    return [g for g in groups if g.items], tools


def _education_languages(lines: list[str]) -> tuple[list[EducationItem], list[LanguageItem]]:
    education: list[EducationItem] = []
    languages: list[LanguageItem] = []
    until_lang = next((i for i, value in enumerate(lines)
                      if _key(value) in {'иностранные языки', 'языки', 'languages'}), len(lines))
    educ = [v.strip() for v in lines[:until_lang]
            if _key(v) not in {'образование'} and v.strip()]
    if educ:
        institution = educ[0]
        degree_line = next((v for v in educ[1:]
                            if re.search(r'бакалавр|магистр|специалист|college|university', v, re.I)), None)
        specialty_match = re.search(r'["«“](.+?)["»”]', degree_line or '')
        specialty = specialty_match.group(1).strip() if specialty_match else None
        degree = degree_line.split(',', 1)[0].strip() if specialty else degree_line
        period = next((v for v in educ[1:] if _DATE_RE.search(v)), None)
        details = [period] if period else []
        # Keep non-duplicate education details that are not represented above.
        for value in educ[1:]:
            if value == degree_line or value == period:
                continue
            if degree_line and re.sub(r'[^а-яa-z0-9]', '', value.casefold()) == re.sub(r'[^а-яa-z0-9]', '', degree_line.casefold()):
                continue
            if value not in details:
                details.append(value)
        education.append(EducationItem(institution=institution, degree=degree,
                                       specialty=specialty, details=details))
    for line in lines[until_lang + 1:]:
        match = re.match(r'^([^:—–-]{2,50})\s*[:—–-]\s*(.*)$', line)
        if match:
            languages.append(LanguageItem(language=match.group(1).strip(),
                                          level=match.group(2).strip() or None))
        elif line.strip() and len(line) < 45:
            languages.append(LanguageItem(language=line.strip()))
    return education, languages


def _additional(lines: list[str]) -> list[AdditionalItem]:
    result: list[AdditionalItem] = []
    current_question: str | None = None
    answer_lines: list[str] = []
    for line in lines:
        if line.endswith('?'):
            if current_question and answer_lines:
                result.append(AdditionalItem(question=current_question, answer=' '.join(answer_lines)))
            current_question, answer_lines = line, []
        elif current_question and line.strip():
            answer_lines.append(line.strip())
    if current_question and answer_lines:
        result.append(AdditionalItem(question=current_question, answer=' '.join(answer_lines)))
    return result


def explicit_document_facts(source_text: str) -> dict:
    """Extract only visually explicit CV sections and project markers."""
    projects = projects_from_markers(source_text)
    if not projects:
        return {}
    # Marker blocks must not corrupt parsing of profile, skills or education.
    plain = source_text.split('[CV_PROJECT_START]', 1)[0]
    tail = source_text.rsplit('[CV_PROJECT_END]', 1)[-1]
    lines = [line.strip() for line in (plain + '\n' + tail).splitlines() if line.strip()]
    skill_lines = _slice_section(lines, start={'навыки и умения', 'навыки'},
                                 ends={'образование', 'образование и языки',
                                       'опыт работы', 'дополнительная информация'})
    educ_lines = _slice_section(lines, start={'образование', 'образование и языки'},
                                ends={'опыт работы', 'дополнительная информация'})
    extra_lines = _slice_section(lines, start={'дополнительная информация'}, ends=set())
    groups, tools = _skills(skill_lines)
    education, languages = _education_languages(educ_lines)
    additional = _additional(extra_lines)
    commercial = next((line.split(':', 1)[1].strip() for line in lines
                       if line.casefold().startswith('опыт коммерческой разработки:')), None)
    target_role = next((line for line in lines[:3]
                        if _key(line) in {'аналитик', 'системный аналитик', 'бизнес-аналитик'}), None)
    role_experience = None
    declared_count = None
    for line in lines[:8]:
        if target_role and line.casefold().startswith(target_role.casefold() + ':'):
            role_experience = line.split(':', 1)[1].split('(', 1)[0].strip()
            match = _PROJECTS_RE.search(line)
            if match:
                declared_count = int(match.group(1))
            break
    warnings = []
    if declared_count is not None and declared_count != len(projects):
        warnings.append(f'В исходнике заявлено {declared_count} проектов, но детально описано {len(projects)}; сохранены все отдельные блоки')
    return {
        'projects': projects, 'skill_groups': groups, 'tools': tools,
        'education': education, 'languages': languages, 'additional': additional,
        'experience': ExperienceSummary(commercial=commercial, role=role_experience),
        'warnings': warnings,
    }
