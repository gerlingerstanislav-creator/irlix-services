from pathlib import Path

from app.models import CanonicalCv
from app.preparser import merge_preparsed, preparse_cv


FIXTURE = Path(__file__).parent / 'fixtures' / 'technical_qa_lead_blocks.txt'


def test_real_layout_fixture_is_split_without_losing_structure():
    parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))

    assert parsed.expected_work_experience == 3
    assert [item.company for item in parsed.seed.work_experience] == ['SENSE IT', 'SENSE IT', 'POISK LLC']
    assert [item.role for item in parsed.seed.work_experience] == [
        'Technical QA Lead',
        'QA Engineer / AQA',
        'QA Engineer',
    ]

    assert parsed.expected_projects == 3
    assert [item.name for item in parsed.seed.projects] == [
        'ATS/CRM',
        'Модуль электронного документооборота (ЭДО)',
        'Система управления аутсорсингом',
    ]

    assert parsed.seed.contacts.email == 'candidate@example.test'
    assert parsed.seed.contacts.telegram == '@candidate'
    assert parsed.seed.contacts.work_format == 'Удалённая работа / Не рассматриваю релокацию'
    assert parsed.seed.languages[0].language == 'Английский'
    assert parsed.seed.languages[0].level == 'b1'
    assert parsed.seed.summary and 'Shift Left' in parsed.seed.summary

    for technology in ('PHP', 'Playwright', 'Selenium', 'PostgreSQL', 'ClickHouse', 'GitLab CI', 'Docker'):
        assert technology in parsed.seed.tools


def test_preparser_restores_items_missed_by_small_llm():
    parsed = preparse_cv(FIXTURE.read_text(encoding='utf-8'))
    llm_result = CanonicalCv(full_name='Артем Белороссов', target_role='Technical QA Lead')

    merged = merge_preparsed(llm_result, parsed)

    assert len(merged.work_experience) == 3
    assert len(merged.projects) == 3
    assert merged.contacts.email == 'candidate@example.test'
    assert merged.summary is not None
    assert any('work_experience восстановлен pre-parser' in warning for warning in merged.warnings)
    assert any('projects восстановлены pre-parser' in warning for warning in merged.warnings)
