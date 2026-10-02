# CV конвертер frontend

Iteration 2 сервиса: загрузка произвольного текстового PDF/DOCX, LLM-нормализация в canonical CV и генерация IRLIX CV из единого шаблона.

## Пользовательский сценарий

- route `/cv-converter/`, рабочий экран `/cv-converter/convert/`;
- настройки LLM доступны отдельной страницей `/cv-converter/settings/`;
- пользователь одним действием загружает PDF или DOCX;
- исходник отображается слева;
- файл отправляется в `cv-converter` backend только на время запроса и не сохраняется;
- backend извлекает текст и передаёт его выбранному `CvExtractionProvider`;
- результат валидируется как canonical CV;
- справа автоматически появляется PDF-preview шаблона `IRLIX CV`;
- DOCX и PDF генерируются из одной canonical-модели и одного renderer.

## LLM providers

Основной локальный режим стенда:

- `llama.cpp` CPU server;
- `Qwen3-4B-GGUF:Q4_K_M`;
- 6 CPU, до 4000 MB RAM;
- внешний API не нужен.

Через страницу настроек можно без restart переключить provider для следующих конвертаций:

- `local` — OpenAI-compatible локальный endpoint;
- `gigachat` — GigaChat API с OAuth credentials;
- `yandex` — Yandex AI Studio;
- `openai_compatible` — произвольный совместимый endpoint, включая MWS.

Для GigaChat доступны поля credentials, scope, model, API URL и OAuth URL. Секрет после сохранения не возвращается frontend: UI получает только признак `credentials_configured`. Пустое поле секрета при повторном сохранении означает «оставить текущее значение».

Настройки сохраняются backend в отдельном persistent volume и применяются на каждый новый parse request, поэтому перезапуск контейнеров для переключения provider не требуется.

## Тайминги

Во время parse frontend показывает один честный таймер серверной обработки вместо нескольких одновременно «активных» этапов, которые невозможно измерить до ответа backend.

После ответа показывается фактическая разбивка:

- извлечение текста;
- structural pre-parser;
- запрос/ответ LLM;
- completeness merge/post-processing;
- генерация PDF.

Таким образом `LLM` больше не включает скрытое время pre-parser/post-processing и можно сравнивать локальную модель и внешних providers по реальному inference latency.

## Layout

- верхняя сервисная полоса содержит название `CV конвертер`;
- рабочая зона занимает оставшуюся высоту viewport;
- левая и правая области не выходят ниже окна браузера;
- исходник и результат прокручиваются независимо;
- preview результата — PDF, сгенерированный тем же backend renderer, который используется для скачивания.

## Ограничения iteration 2

- legacy `.doc` пока требует предварительного сохранения в `.docx`;
- PDF scan без text layer требует OCR и пока возвращает явную ошибку;
- оригиналы, canonical JSON и renders не сохраняются после запроса;
- при выборе внешнего provider текст CV передаётся соответствующему поставщику;
- локальный `cv-llm` остаётся запущенным при выборе внешнего provider, чтобы переключение обратно было мгновенным;
- интеграции с Clients/Recruitment пока отсутствуют.

## Локальный запуск

При запуске всей платформы используется дополнительный overlay `docker-compose.cv.yml`. `cv-web` публикуется на `127.0.0.1:8096`, backend — на `127.0.0.1:8097`, а `cv-llm` доступен только внутри Docker network.

Host nginx маршрутизирует `/cv-converter/` на frontend и `/api/cv-converter/` на backend. Старый `/cv/*` перенаправляется на `/cv-converter/`.
