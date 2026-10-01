# CV конвертер frontend

Iteration 2 сервиса: загрузка произвольного текстового PDF/DOCX, LLM-нормализация в canonical CV и генерация IRLIX CV из единого шаблона.

## Пользовательский сценарий

- route `/cv-converter/`, рабочий экран `/cv-converter/convert/`;
- пользователь одним действием загружает PDF или DOCX;
- исходник отображается слева;
- файл отправляется в `cv-converter` backend только на время запроса и не сохраняется;
- backend извлекает текст и передаёт его выбранному `CvExtractionProvider`;
- результат валидируется как canonical CV;
- справа автоматически появляется PDF-preview шаблона `IRLIX CV`;
- DOCX и PDF генерируются из одной canonical-модели и одного renderer.

## LLM providers

Основной режим стенда:

- `CV_LLM_PROVIDER=local`;
- `llama.cpp` CPU server;
- Cotype Nano, quantization Q4_K_M;
- внешние API и ключи не требуются.

Поддержаны сменные provider adapters:

- `local` — OpenAI-compatible локальный endpoint;
- `gigachat` — GigaChat API с OAuth;
- `yandex` — OpenAI-compatible Yandex AI Studio;
- `mws` / `openai_compatible` — произвольный совместимый внешний endpoint, включая MWS/Cotype cloud.

Смена provider не меняет canonical schema, UI и renderer.

## Layout

- верхняя сервисная полоса содержит название `CV конвертер`;
- рабочая зона занимает оставшуюся высоту viewport;
- левая и правая области не выходят ниже окна браузера;
- исходник и результат прокручиваются независимо;
- preview результата — PDF, сгенерированный тем же backend renderer, который используется для скачивания.

## Ограничения iteration 2

- legacy `.doc` пока требует предварительного сохранения в `.docx`;
- PDF scan без text layer требует OCR и пока возвращает явную ошибку;
- маленькая локальная 1.5B-модель используется для проверки качества и реального расхода ресурсов; при недостаточном качестве provider можно переключить без изменения сервиса;
- оригиналы, canonical JSON и renders не сохраняются после запроса;
- интеграции с Clients/Recruitment пока отсутствуют.

## Локальный запуск

При запуске всей платформы используется дополнительный overlay `docker-compose.cv.yml`. `cv-web` публикуется на `127.0.0.1:8096`, backend — на `127.0.0.1:8097`, а `cv-llm` доступен только внутри Docker network.

Host nginx маршрутизирует `/cv-converter/` на frontend и `/api/cv-converter/` на backend. Старый `/cv/*` перенаправляется на `/cv-converter/`.
