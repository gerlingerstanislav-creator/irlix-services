# Ресурсный монитор Dashboard

## Доступ и интерфейс

- Раздел **Ресурсный монитор** в левом меню Dashboard, без верхних вкладок; прямой маршрут `/resources/` (History API, Back/Forward).
- Доступ только по актуальным эффективным ролям Employees: `platform-admin` **или** `system-admin`.
- `platform-tester` и остальные роли доступа не имеют. Эта явная политика не использует более широкий shared `isPlatformAdminAccess`.
- Backend перед каждым чтением вызывает Employees `/access/me` с Bearer пользователя. Проверка JWT принадлежит Employees; ошибочный токен, отсутствие роли, недоступность проверки не открывают данные.
- Ответы API имеют `Cache-Control: no-store`; отказ 401/403 очищает показанные данные и останавливает polling. Скрытие пункта меню не заменяет серверную проверку.

На одной странице: CPU/RAM/cache/swap/root disk VM; сортируемая таблица логических сервисов; раскрываемые frontend/backend/workers с действующими лимитами, состоянием, количеством перезапусков и признаком OOM текущего контейнера; история RAM/CPU VM или выбранного сервиса за час, сутки и семь дней.

## Сбор и семантика метрик

Отдельный контейнер `resource-monitor` из `docker-compose.resources.yml` каждые 10 секунд независимо от браузера читает host `/proc` и Docker Engine API через Unix socket. CPU вычисляется между замерами; первый замер не выдаётся за нулевую нагрузку. Используется фиксированный allowlist GET endpoints; Docker mutations, логи и env контейнеров не передаются. Собственного HTTP listener и сети нет. Docker socket даже с `:ro` остаётся привилегированной возможностью; его получает только изолированный сборщик, не web/API Platform Core.

На host-монтировании `/host/root` вызывается только `statvfs` для корневого filesystem, без обхода файлов. `/host/proc` используется только для `meminfo`, `stat`, `loadavg`. Collector read-only, с отключёнными capabilities, `no-new-privileges`, лимитами 96 MiB RAM / 0.25 CPU и единственным writable data volume (кроме tmpfs).

Список контейнеров фильтруется по Compose project `RESOURCE_MONITOR_PROJECT` (по умолчанию `irlix-services`), включает остановленные контейнеры, исключает one-off jobs. В штатном CI project name уже фиксирован. При локальном запуске использовать `--project-name irlix-services` или задать переменную своего проекта. Backend/frontend/background components группируются по владельцу; PostgreSQL, Redis, RabbitMQ и Keycloak остаются отдельными строками. Память общей БД не распределяется по бизнес-сервисам.

- RAM VM: `MemTotal - MemAvailable`; доступная память и файловый cache показаны отдельно.
- RAM контейнера «всего»: cgroup memory usage, включает кеш.
- «Без неактивного кеша»: usage минус inactive file cache (cgroup v1/v2); это приближение рабочего набора, а не вся память процесса/LLM.
- CPU контейнера: число занятых ядер, `1.00` = одно полностью занятое ядро. CPU VM: процент от всей VM. Лимит `null` означает отсутствие установленного лимита, не физическую ёмкость сервера.
- Сумма RAM контейнеров не равна занятости VM: отличаются определения, учёт кеша/shared pages и процессы вне проекта.

## Хранение и API

Collector атомарно заменяет `current.json` и пишет один наблюдаемый sample в минуту в SQLite `history.sqlite`. История — последние 7 дней; удалённые страницы переиспользуются SQLite. При уменьшении количества компонентов размер файла может не уменьшиться немедленно, но старые записи удалены. Volume `resource_monitor_data` переживает container replacement и deploy, Platform Core монтирует его только `:ro` и использует SQLite `mode=ro`, `query_only`.

- `GET /api/platform/resources` — текущие VM/services/components, collected time, partial/stale flags.
- `GET /api/platform/resources/history?period=1h|24h|7d&service=__host__|service-id` — средние и наблюдавшиеся пики; buckets 1/5/30 минут, не более ~480 точек. Первый период содержит до 60 точек.
- `GET /api/platform/resources/health` — public CI liveness (`status` only, без метрик/названий); проверяет свежесть <45 секунд и полноту сбора.

Сбой сбора сохраняет последний snapshot, после 45 секунд UI явно отмечает устаревание. Ошибка отдельного контейнера отмечается partial и не попадает в историю как нулевое потребление. Графики разрываются при пропусках. Лимиты выводятся по компонентам, их сумма не выдаётся за гарантированное выделение логическому сервису.

## Проверки и следующий этап

Collector tests в `services/resource-monitor/tests.py` запускаются при image build; Platform Core build запускает PHP lint и `tests/resources_smoke.php` (эффективные роли, отказ Employees, stale snapshot, read-only SQLite history/validation). Portal build использует lockfile; Node tests проверяют роли и route. Registry и весь Compose stack проходят общий CI; новый collector зарегистрирован в `infra/ci/services.json`.

Первая версия **не меняет** лимиты контейнеров и мощности VM. Следующий этап: отдельный operations adapter, сохранение desired limits независимо от deploy, проверка бюджета VM, аудит и rollback. Ограничения RAM/CPU не являются резервированием мощности и не увеличивают физические ресурсы VM.
