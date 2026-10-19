# AI-команда Irlix Services

## Роли и смысл
PM (главный чат) координирует Analyst, Designer, сервисных Developer:<service>, QA и PlatformEngineer. ProductAdvisor предлагает идеи только по запросу владельца. Роль — это профиль работы, а не непрерывно запущенный AI. Разработчик отвечает за frontend и backend одного сервиса; соседей читает только через нужный контракт по docs/CONTEXT_SCOPE.md. Дизайнер отвечает за packages/ui и UX; аналитик — за бизнес-спецификации ideas; QA — независимая проверка.

## Как роли общаются
GitHub Pull Request — общий канал и единая версия кода (SHA). Ветки начинаются от development. На PR автоматически работают существующие детерминированные CI/QA checks, отдельно, при включении, AI Team advisory review. AI QA и Analyst анализируют diff независимо. Designer вызывается на UI-изменения, PlatformEngineer — на shared/infra и межсервисные изменения. Затем PM видит результаты всех исполнителей, публикует решение и указывает Developer:<service> — получателя замечаний.

Комментарии AI содержат CHANGES_REQUESTED (надо исправить), NEEDS_INFO (недостаточно оснований) или NO_FINDINGS (по доступному тексту проблем не обнаружено, но тестирование НЕ подтверждено). После push исправлений PR проверяется повторно. Нельзя заменять этими оценками CI, реальные browser/backend интеграционные тесты или приёмку на production.

На этой стадии developer-агент НЕ стартует автоматически по отзыву PM: для этого необходим отдельный coding runtime с правом создавать feature-ветки, обособленная очередь заданий и ограничение циклов. GitHub Actions не присылает сообщения непосредственно в чат ChatGPT; результаты доступны в PR/Actions, и PM запрашивает их из GitHub во время работы.

## Безопасность
irlix-services — публичный, ideas — приватный. Запрещено публиковать фрагменты закрытых ТЗ, коммерческие или персональные данные в публичном PR. Поэтому при публичном канале скрипт AI Team НЕ получает private ideas; Analyst отмечает NEEDS_INFO по бизнес-приёмке. Для анализа закрытой документации сначала нужен приватный канал обсуждений, с оценкой последствий приватности исходного репозитория. Нельзя делать этот переход самостоятельно.

GitHub workflow pull_request_target работает только с trusted base SHA (никогда не checkout и не исполнение кода PR). Принимаются лишь PR из веток того же репозитория, base=development, не draft. Скрипт отправляет ограниченный diff на OpenAI Responses API, с store=false, не имея прав изменять репозиторий или делать deploy. Данные PR могут быть прочитаны внешним API только после явного включения владельцем.

## Как включить AI-review (действия владельца)
GitHub → irlix-services → Settings → Secrets and variables → Actions:
1. Secrets → New repository secret → OPENAI_API_KEY: отдельный OpenAI API project key, с разумными лимитами использования. ChatGPT Plus/Pro не равен API billing. Никогда не размещать ключ в чате/репозитории.
2. Variables → New repository variable → AI_TEAM_ENABLED: true. Опционально AI_TEAM_MODEL: gpt-5-mini (либо другой поддерживаемый Responses модельный ID).
3. Разрешить штатный GitHub Actions; GITHUB_TOKEN с pull-requests: write разрешён только для этого workflow. Fork PR и draft исключены.
4. После настройки создать/обновить PR в development. В Actions появится AI Team advisory review, а в PR — сообщения ролей и заключение PM.
5. Отключение расходов: AI_TEAM_ENABLED=false. Зелёный AI-анализ не разрешает merge и не запускает production.

Дополнительный IDEAS_READ_TOKEN (fine-grained GitHub token только Contents:read для private ideas) допустим лишь при переносе канала в private. На публичном irlix-services он не используется.

## Реализация
- .github/agents/*.agent.md — инструкции ролей;
- scripts/agents/route.mjs — затронутые сервисы и маршрутизация;
- scripts/agents/review.mjs — отдельные ответы рецензентов, сводка PM и комментарии в PR;
- .github/workflows/ai-team.yml — безопасный фоновый запуск по PR после opt-in;
- scripts/qa/tests/agent-route.test.mjs — проверки маршрутизации;
- docs/QA.md — независимые исполняемые проверки, без TEST стенда.

main/production остаётся доступен к выпуску только после прямой команды владельца по существующим правилам. Никакой AI не может запускать release самостоятельно.
