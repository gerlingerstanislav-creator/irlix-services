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

Local stand mode: llama.cpp + Cotype Nano Q4_K_M.

## Data policy

Backend не сохраняет исходный файл, extracted text, canonical JSON или render. LLM prompt запрещает добавлять факты, отсутствующие в исходнике.

## Testing

`python -m app.smoke` выполняет реальный provider inference на синтетическом CV и проверяет ключевые canonical поля. CI отдельно проверяет Python compilation и DOCX/PDF renderer без LLM.
