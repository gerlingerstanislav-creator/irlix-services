from __future__ import annotations

from typing import Optional
from pydantic import BaseModel, Field


class ContactInfo(BaseModel):
    email: Optional[str] = None
    phone: Optional[str] = None
    telegram: Optional[str] = None
    location: Optional[str] = None
    links: list[str] = Field(default_factory=list)


class SkillGroup(BaseModel):
    title: str
    items: list[str] = Field(default_factory=list)


class EducationItem(BaseModel):
    institution: str = ""
    degree: Optional[str] = None
    specialty: Optional[str] = None
    period: Optional[str] = None
    status: Optional[str] = None


class LanguageItem(BaseModel):
    language: str
    level: Optional[str] = None


class ProjectExperience(BaseModel):
    role: str = ""
    period: Optional[str] = None
    team: Optional[str] = None
    technologies: list[str] = Field(default_factory=list)
    project_name: str = ""
    description: Optional[str] = None
    responsibilities: list[str] = Field(default_factory=list)


class AdditionalItem(BaseModel):
    title: str
    text: str


class CanonicalCv(BaseModel):
    full_name: str = ""
    position: str = ""
    commercial_experience: Optional[str] = None
    role_experience: Optional[str] = None
    summary: list[str] = Field(default_factory=list)
    skill_groups: list[SkillGroup] = Field(default_factory=list)
    tools: list[str] = Field(default_factory=list)
    education: list[EducationItem] = Field(default_factory=list)
    languages: list[LanguageItem] = Field(default_factory=list)
    projects: list[ProjectExperience] = Field(default_factory=list)
    additional_info: list[AdditionalItem] = Field(default_factory=list)
    contacts: ContactInfo = Field(default_factory=ContactInfo)
    certifications: list[str] = Field(default_factory=list)


class ConvertRequest(BaseModel):
    text: str = Field(min_length=1, max_length=120_000)
    source_name: str = "cv"


class ExtractionMetrics(BaseModel):
    provider: str
    model: str
    chunks: int
    input_chars: int
    inference_ms: int


class ConvertResponse(BaseModel):
    render_id: str
    cv: CanonicalCv
    metrics: ExtractionMetrics
    docx_url: str
    pdf_url: str
