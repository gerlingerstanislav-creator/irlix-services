# Backlog — IRLIX Services

Актуальный источник задач — [GitHub Issues](https://github.com/gerlingerstanislav-creator/irlix-services/issues?q=is%3Aissue). Этот файл — индекс и правила, **не второй независимый список задач**. Состояние кода сверяется с `development`; `main` отражает последний production-релиз.

## Статусы
- **К выполнению** — задача поставлена, реализация или инвентаризация не началась.
- **В работе** — есть активная реализация/PR, критерии приёмки не подтверждены.
- **На QA** — требуется сверка реализации, регрессия или воспроизведение наблюдения; не считать автоматически реализованным или успешно протестированным.
- **Отложено** — решение или реализация перенесены на будущую итерацию.

Статус фиксируется в начале Issue как «Статус бэклога». Один Issue — один набор критериев приёмки; при изменениях обновлять Issue и затронутое ТЗ в `ideas`. Закрывать только после подтверждения выполнения, необходимых тестов и отражения документации. Не считать закрытие Issue подтверждением выкладки в production.

## К выполнению
- [#287](https://github.com/gerlingerstanislav-creator/irlix-services/issues/287) Timesheets — месячная матрица часов, сначала сверить с реализацией и ТЗ.
- [#291](https://github.com/gerlingerstanislav-creator/irlix-services/issues/291) CI/CD — замеры и варианты оптимизации.

## В работе
- [#279](https://github.com/gerlingerstanislav-creator/irlix-services/issues/279) Общая тёмная тема.
- [#280](https://github.com/gerlingerstanislav-creator/irlix-services/issues/280) Общая типографика.
- [PR #283](https://github.com/gerlingerstanislav-creator/irlix-services/pull/283) — незавершённая интеграция темы, типографики и цветовых токенов. **Не вливать без синхронизации с development и CI.**

## На QA / сверку
- [#273–#278](https://github.com/gerlingerstanislav-creator/irlix-services/issues?q=is%3Aissue+is%3Aopen+vacations) Vacations — доступы, маршруты, самосогласование, аудит, API, UX; учитывать последние доработки до повторных исправлений.
- [#288](https://github.com/gerlingerstanislav-creator/irlix-services/issues/288) Resource Monitor — RAM/диск и «Система / прочее».
- [#289](https://github.com/gerlingerstanislav-creator/irlix-services/issues/289) Dashboard — карта сервисов по контурам.
- [#290](https://github.com/gerlingerstanislav-creator/irlix-services/issues/290) Migration — точки отката, права, этап файлов.

## Отложено
- [#284](https://github.com/gerlingerstanislav-creator/irlix-services/issues/284) Редактирование параметров дизайн-системы из интерфейса — только обсуждение следующей итерации.

## Незавершённая организационная работа
- [PR #272](https://github.com/gerlingerstanislav-creator/irlix-services/pull/272) — прежняя работа над статусом продукта, бэклогом и changelog; при интеграции согласовать с этим индексом, избегать второго списка задач.

Последняя инвентаризация: 2026-10-09. Для текущего списка всегда открывать GitHub Issues.
