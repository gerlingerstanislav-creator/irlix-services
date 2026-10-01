from __future__ import annotations

import asyncio
import json

from .providers import build_provider


# Representative of the production pipeline: structural headings and block
# boundaries are intentionally preserved so the pre-parser participates in the
# smoke instead of testing the LLM in isolation.
FIXTURE = """Иван Петров

Системный аналитик

Опыт коммерческой разработки: 4 года
Системный аналитик: 4 года

Опыт работы

ООО Пример
Системный аналитик (Январь 2023 - настоящее время)

Сбор и согласование требований, моделирование BPMN и проектирование REST API.

Проекты

Корпоративная информационная система

Система автоматизации внутренних бизнес-процессов компании, используемая сотрудниками нескольких подразделений.

Skills

BPMN
UML
REST API
PostgreSQL
Kafka
"""


async def main() -> None:
    provider = build_provider()
    cv, metrics = await provider.extract(FIXTURE)

    if not cv.full_name:
        raise SystemExit('Smoke failed: full_name is empty')
    if not cv.target_role:
        raise SystemExit('Smoke failed: target_role is empty')
    if (metrics.get('expected_work_experience') or 0) < 1:
        raise SystemExit('Smoke failed: pre-parser did not detect work experience')
    if (metrics.get('expected_projects') or 0) < 1:
        raise SystemExit('Smoke failed: pre-parser did not detect projects')
    if not cv.work_experience:
        raise SystemExit('Smoke failed: work_experience is empty after completeness merge')
    if not cv.projects:
        raise SystemExit('Smoke failed: projects are empty after completeness merge')

    print(json.dumps({
        'status': 'ok',
        'provider': metrics.get('provider'),
        'model': metrics.get('model'),
        'llm_ms': metrics.get('llm_ms'),
        'full_name': cv.full_name,
        'target_role': cv.target_role,
        'expected_work_experience': metrics.get('expected_work_experience'),
        'work_experience': len(cv.work_experience),
        'expected_projects': metrics.get('expected_projects'),
        'projects': len(cv.projects),
        'warnings': cv.warnings,
    }, ensure_ascii=False))


if __name__ == '__main__':
    asyncio.run(main())
