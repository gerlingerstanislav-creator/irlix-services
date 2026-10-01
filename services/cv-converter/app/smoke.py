from __future__ import annotations

import asyncio
import json

from .providers import build_provider


FIXTURE = """Иван Петров
Системный аналитик
Опыт коммерческой разработки: 4 года
Системный аналитик: 4 года

Ключевые навыки:
Сбор и анализ требований
BPMN, UML
REST API

Используемые инструменты:
Jira, Confluence, Postman, PostgreSQL

Образование:
Московский технический университет, бакалавриат, Информационные системы

Иностранные языки:
Русский — родной
Английский — B2

Опыт работы
Системный аналитик
01.2023 - настоящее время
Команда 1 PM, 2 SA, 4 backend, 2 QA
Jira, Confluence, PostgreSQL, Kafka
Проект: Корпоративная информационная система
Описание проекта: автоматизация внутренних бизнес-процессов компании.
Выполняемые задачи:
- сбор и согласование требований
- моделирование бизнес-процессов BPMN
- проектирование REST API
- проектирование модели данных
"""


async def main() -> None:
    provider = build_provider()
    cv, metrics = await provider.extract(FIXTURE)
    if not cv.full_name:
        raise SystemExit('Smoke failed: full_name is empty')
    if not cv.target_role:
        raise SystemExit('Smoke failed: target_role is empty')
    if not cv.projects:
        raise SystemExit('Smoke failed: projects are empty')
    print(json.dumps({
        'status': 'ok',
        'provider': metrics.get('provider'),
        'model': metrics.get('model'),
        'llm_ms': metrics.get('llm_ms'),
        'full_name': cv.full_name,
        'target_role': cv.target_role,
        'projects': len(cv.projects),
        'warnings': cv.warnings,
    }, ensure_ascii=False))


if __name__ == '__main__':
    asyncio.run(main())
