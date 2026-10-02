from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field


class ExperienceSummary(BaseModel):
    commercial: str | None = None
    role: str | None = None


class ContactInfo(BaseModel):
    email: str | None = None
    phone: str | None = None
    telegram: str | None = None
    location: str | None = None
    work_format: str | None = None
    links: list[str] = Field(default_factory=list)


class SkillGroup(BaseModel):
    title: str
    items: list[str] = Field(default_factory=list)


class EducationItem(BaseModel):
    institution: str | None = None
    degree: str | None = None
    specialty: str | None = None
    status: str | None = None
    details: list[str] = Field(default_factory=list)


class LanguageItem(BaseModel):
    language: str
    level: str | None = None


class WorkExperienceItem(BaseModel):
    company: str | None = None
    role: str | None = None
    dates: str | None = None
    description: str | None = None
    responsibilities: list[str] = Field(default_factory=list)
    achievements: list[str] = Field(default_factory=list)
    technologies: list[str] = Field(default_factory=list)


class ProjectItem(BaseModel):
    role: str | None = None
    dates: str | None = None
    team: str | None = None
    technologies: list[str] = Field(default_factory=list)
    name: str | None = None
    description: str | None = None
    responsibilities: list[str] = Field(default_factory=list)


class AdditionalItem(BaseModel):
    question: str | None = None
    answer: str


class ExtraSection(BaseModel):
    title: str
    items: list[str] = Field(default_factory=list)


class CanonicalCv(BaseModel):
    full_name: str | None = None
    target_role: str | None = None
    source_language: str | None = None
    contacts: ContactInfo = Field(default_factory=ContactInfo)
    summary: str | None = None
    experience: ExperienceSummary = Field(default_factory=ExperienceSummary)
    work_experience: list[WorkExperienceItem] = Field(default_factory=list)
    skill_groups: list[SkillGroup] = Field(default_factory=list)
    tools: list[str] = Field(default_factory=list)
    education: list[EducationItem] = Field(default_factory=list)
    languages: list[LanguageItem] = Field(default_factory=list)
    projects: list[ProjectItem] = Field(default_factory=list)
    additional: list[AdditionalItem] = Field(default_factory=list)
    extra_sections: list[ExtraSection] = Field(default_factory=list)
    warnings: list[str] = Field(default_factory=list)


class ParseMetrics(BaseModel):
    provider: str
    model: str | None = None
    extraction_ms: int
    preparse_ms: int = 0
    llm_ms: int
    postprocess_ms: int = 0
    total_ms: int
    source_chars: int
    input_tokens: int | None = None
    output_tokens: int | None = None
    expected_work_experience: int | None = None
    expected_projects: int | None = None


class ParseResponse(BaseModel):
    cv: CanonicalCv
    metrics: ParseMetrics


class RenderRequest(BaseModel):
    cv: CanonicalCv
    format: Literal['docx', 'pdf']
