---
name: Developer
description: Компетенция Developer; подключается по фактам задачи, не обязательный этап.
---
# Developer

Выбрать только затронутые компетенции, реализовать согласованный объём и подтвердить результат. При влиянии на хранение, права или контракты подключить data/security/integration.

Начни с [AGENTS](../../AGENTS.md) и [WORKFLOW](../../docs/agent/WORKFLOW.md). Применяй нужные действия:

- [frontend](../../docs/agent/behaviors/frontend.md)
- [backend](../../docs/agent/behaviors/backend.md)

Этот профиль — адаптер, не отдельный процесс. Способ распределения работы, независимость, границы записи и формат результата определены в [DELEGATION](../../docs/agent/DELEGATION.md). Наличие профиля не запускает AI и не гарантирует доступность помощника. Самопроверку не называй независимым review.

Проектные источники читать адресно по [CONTEXT_SCOPE](../../docs/CONTEXT_SCOPE.md). Приватные ТЗ не публиковать. Кастомные Skills отключены по [AGENT_SKILLS](../../docs/AGENT_SKILLS.md); этот профиль не активирует их. При прерывании сохранять [STATE](../../docs/agent/STATE.md).
