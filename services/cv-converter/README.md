# CV Converter backend

FastAPI backend iteration 2 для `CV конвертер`.

## Pipeline

`PDF/DOCX -> text extraction -> CvExtractionProvider -> CanonicalCv -> IRLIX DOCX -> PDF`

API:

- `GET /api/health` — backend/provider configuration health;
- `GET /api/health/llm` — local inference readiness;
- `POST /api/parse` — multipart `file`, возвращает `CanonicalCv` и metrics;
- `POST /api/render/docx` — принимает `CanonicalCv`, возвращает DOCX;
- `POST /api/render/pdf` — принимает `CanonicalCv`, возвращает PDF из того же DOCX renderer.

`parse` и render endpoints требуют platform Bearer JWT. Health endpoints используются инфраструктурой.

## Providers

`CV_LLM_PROVIDER`:

- `local` — OpenAI-compatible local endpoint, default;
- `gigachat` — GigaChat OAuth/API;
- `yandex` — Yandex AI Studio OpenAI-compatible endpoint;
- `mws` / `openai_compatible` — generic compatible endpoint.

Local stand mode: llama.cpp + Cotype Nano `Q3_K_M`. Для текущей CPU-only VM output локальной модели ограничен 2200 токенами, чтобы один запрос укладывался в bounded HTTP/deploy timeout.

Tiny local models могут пропускать отдельные поля. После LLM выполняются только консервативные fallback-правила: `target_role` восстанавливается из явного заголовка CV, а `projects` — только из явно размеченных блоков `Проект:` / `Описание проекта:` / `Выполняемые задачи:`. Новые факты не генерируются.

## Data policy

Backend не сохраняет исходный файл, extracted text, canonical JSON или render. LLM prompt запрещает добавлять факты, отсутствующие в исходнике.

## Deployment note

Host nginx route `/api/cv-converter/` должен быть активирован до inference smoke. Deploy reload-ит nginx сразу после успешного `nginx -t`, поэтому падение последующего CV smoke не оставляет API на старой routing-конфигурации.

## Testing

`python -m app.smoke` выполняет реальный provider inference на синтетическом CV и проверяет ключевые canonical поля. CI отдельно проверяет Python compilation и DOCX/PDF renderer без LLM.
