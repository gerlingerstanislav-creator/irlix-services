# CV Converter backend

FastAPI backend iteration 2 для `CV конвертер`.

## Pipeline

`PDF/DOCX -> block-preserving extraction -> structural pre-parser -> CvExtractionProvider -> completeness merge -> CanonicalCv -> IRLIX DOCX -> PDF`

Структурный pre-parser работает до LLM и сохраняет то, что можно определить без генеративной модели: контакты, summary-кандидат, отдельные места работы, явно выделенные проекты, языки и список technologies/tools. LLM получает уже разделённый документ с секциями `[PROFILE]`, `[WORK_EXPERIENCE]`, `[PROJECTS]`, `[SKILLS]` и отвечает за нормализацию в canonical schema.

После LLM выполняется completeness merge. Если pre-parser детерминированно нашёл N мест работы или N проектов, результат модели не может молча вернуть меньше: пропущенные структурные элементы восстанавливаются из исходного текста без генерации новых фактов. В metrics возвращаются `expected_work_experience` и `expected_projects`.

API:

- `GET /api/health` — backend/provider configuration health;
- `GET /api/health/llm` — local inference readiness;
- `POST /api/parse` — multipart `file`, возвращает `CanonicalCv` и metrics;
- `POST /api/render/docx` — принимает `CanonicalCv`, возвращает DOCX;
- `POST /api/render/pdf` — принимает `CanonicalCv`, возвращает PDF из того же DOCX renderer.

`parse` и render endpoints требуют platform Bearer JWT. Health endpoints используются инфраструктурой.

## Canonical model

Помимо имени/роли, skills, education, languages и projects canonical model хранит:

- `contacts` — email, phone, Telegram, location, work format, links;
- `summary` — профессиональное описание кандидата;
- `work_experience[]` — работодатель, роль, период, описание, responsibilities, achievements, technologies;
- `projects[]` — проектный опыт отдельно от работодателей.

Разделение `work_experience` и `projects` принципиально: если исходное CV не связывает конкретный проект с конкретным работодателем, converter не должен придумывать эту связь.

## Providers

`CV_LLM_PROVIDER`:

- `local` — OpenAI-compatible local endpoint, default;
- `gigachat` — GigaChat OAuth/API;
- `yandex` — Yandex AI Studio OpenAI-compatible endpoint;
- `mws` / `openai_compatible` — generic compatible endpoint.

Local stand mode: llama.cpp + Cotype Nano `Q3_K_M`. Output локальной модели ограничен 2200 токенами. После увеличения RAM VM до 8 ГБ контейнеру `cv-llm` разрешено до 2600 MB памяти и 4 CPU; сама модель и квантование не менялись, чтобы отдельно измерить влияние снятия прежних resource limits на latency.

## Source extraction

PDF extraction использует PyMuPDF `blocks` с сохранением границ текстовых блоков. Это важно для резюме с колонками и отдельными карточками: компания+должность, название проекта, описание проекта и skills больше не склеиваются в одну плоскую строку до попадания в parser.

DOCX extraction сохраняет границы абзацев и строк таблиц как отдельные блоки.

Сканированные PDF без text layer по-прежнему требуют OCR и возвращают понятную ошибку.

## Data policy

Backend не сохраняет исходный файл, extracted text, canonical JSON или render. LLM prompt запрещает добавлять факты, отсутствующие в исходнике.

## Deployment note

Host nginx route `/api/cv-converter/` должен быть активирован до inference smoke. Deploy reload-ит nginx сразу после успешного `nginx -t`, поэтому падение последующего CV smoke не оставляет API на старой routing-конфигурации.

## Testing

`tests/fixtures/technical_qa_lead_blocks.txt` — обезличенный regression fixture, построенный по реальному CV, на котором прежний pipeline терял одну должность, все реальные проекты, summary и часть skills.

`python -m unittest discover -s tests -p 'test_*.py' -v` проверяет, что structural parser сохраняет 3 места работы, 3 проекта, summary, contacts/language и ключевые technologies, а completeness merge восстанавливает элементы, пропущенные маленькой LLM.

`python -m app.smoke` выполняет реальный provider inference на синтетическом CV и проверяет ключевые canonical поля. CV Converter Check компилирует backend/tests, запускает regression suite и отдельно проверяет DOCX/PDF renderer без LLM.
