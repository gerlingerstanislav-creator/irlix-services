# CV Converter backend

FastAPI backend iteration 2 для `CV конвертер`.

## Pipeline

`PDF/DOCX -> block-preserving extraction -> structural pre-parser -> CvExtractionProvider -> completeness merge -> CanonicalCv -> IRLIX DOCX -> PDF`

Структурный pre-parser работает до LLM и сохраняет то, что можно определить без генеративной модели: контакты, summary-кандидат, отдельные места работы, явно выделенные проекты, языки и список technologies/tools. LLM получает уже разделённый документ с секциями `[PROFILE]`, `[WORK_EXPERIENCE]`, `[PROJECTS]`, `[SKILLS]` и отвечает за нормализацию в canonical schema.

После LLM выполняется completeness merge. Если pre-parser детерминированно нашёл N мест работы или N проектов, результат модели не может молча вернуть меньше: пропущенные структурные элементы восстанавливаются из исходного текста без генерации новых фактов. В metrics возвращаются `expected_work_experience` и `expected_projects`.

API:

- `GET /api/health` — backend/provider configuration health;
- `GET /api/health/llm` — readiness текущего provider;
- `GET /api/settings` — текущая runtime-конфигурация без секретов;
- `PUT /api/settings` — сохранить provider и его параметры;
- `POST /api/settings/test` — проверить сохранённого provider;
- `POST /api/parse` — multipart `file`, возвращает `CanonicalCv` и metrics;
- `POST /api/render/docx` — принимает `CanonicalCv`, возвращает DOCX;
- `POST /api/render/pdf` — принимает `CanonicalCv`, возвращает PDF из того же DOCX renderer.

`parse`, render и settings endpoints требуют platform Bearer JWT. Health endpoints используются инфраструктурой.

## Runtime provider settings

Env-переменные остаются fallback/default-конфигурацией, но активный provider теперь можно менять без restart. Runtime settings сохраняются в `/data/settings.json`; production compose монтирует для этого отдельный persistent volume `cv_settings`.

Секретные поля (`credentials`, `api_key`) не возвращаются через `GET /api/settings`. Вместо этого frontend получает `*_configured=true/false`. Пустой секрет в `PUT` означает «не менять сохранённое значение». Файл создаётся с mode `0600`.

Поддержаны:

- `local` — base URL + model;
- `gigachat` — credentials, scope, model, API URL, OAuth URL;
- `yandex` — API key, folder ID, model, base URL;
- `openai_compatible` / `mws` — base URL, model и optional API key.

`build_provider()` читает runtime settings при каждом новом parse, поэтому следующая конвертация сразу использует сохранённого provider.

## Canonical model

Помимо имени/роли, skills, education, languages и projects canonical model хранит:

- `contacts` — email, phone, Telegram, location, work format, links;
- `summary` — профессиональное описание кандидата;
- `work_experience[]` — работодатель, роль, период, описание, responsibilities, achievements, technologies;
- `projects[]` — проектный опыт отдельно от работодателей.

Разделение `work_experience` и `projects` принципиально: если исходное CV не связывает конкретный проект с конкретным работодателем, converter не должен придумывать эту связь.

## Providers

Local stand mode: `llama.cpp` + `Qwen3-4B-GGUF:Q4_K_M`. На стенде с 8 CPU / 8 GB RAM для `cv-llm` задано до 6 CPU и жёсткий лимит 4000 MB RAM. Контекст остаётся 8192 токенов, output ограничен 2600 токенами. Для снижения расхода памяти KV-cache хранится в `q8_0`, reasoning отключён.

При переключении на внешний provider локальный `cv-llm` контейнер пока не останавливается: это сохраняет мгновенный rollback на local и не меняет resource orchestration во время пользовательского запроса.

## Метрики

`ParseMetrics` теперь разделяет фактические backend этапы:

- `extraction_ms` — чтение/извлечение текста PDF/DOCX;
- `preparse_ms` — structural pre-parser;
- `llm_ms` — только сетевой/inference этап provider, включая retry при несовместимом constrained output;
- `postprocess_ms` — header fallback + completeness merge;
- `total_ms` — полный `/parse` request.

PDF render измеряется frontend отдельно, потому что это отдельный API request после успешного parse.

## Source extraction

PDF extraction использует PyMuPDF `blocks` с сохранением границ текстовых блоков. Это важно для резюме с колонками и отдельными карточками: компания+должность, название проекта, описание проекта и skills больше не склеиваются в одну плоскую строку до попадания в parser.

DOCX extraction сохраняет границы абзацев и строк таблиц как отдельные блоки.

Сканированные PDF без text layer по-прежнему требуют OCR и возвращают понятную ошибку.

## Data policy

Backend не сохраняет исходный файл, extracted text, canonical JSON или render. LLM prompt запрещает добавлять факты, отсутствующие в исходнике.

При внешнем provider extracted CV text передаётся соответствующему поставщику. Credentials провайдера хранятся серверной runtime-конфигурацией и не возвращаются browser client.

## Deployment note

Host nginx route `/api/cv-converter/` должен быть активирован до inference smoke. Deploy reload-ит nginx сразу после успешного `nginx -t`, поэтому падение последующего CV smoke не оставляет API на старой routing-конфигурации.

## Testing

`tests/fixtures/technical_qa_lead_blocks.txt` — обезличенный regression fixture, построенный по реальному CV, на котором прежний pipeline терял одну должность, все реальные проекты, summary и часть skills.

`python -m unittest discover -s tests -p 'test_*.py' -v` проверяет structural parser и persistence/masking runtime settings.

`python -m app.smoke` выполняет реальный provider inference на синтетическом CV и проверяет ключевые canonical поля. CV Converter Check компилирует backend/tests, запускает regression suite и отдельно проверяет DOCX/PDF renderer без LLM.
