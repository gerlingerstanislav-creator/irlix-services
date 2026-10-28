FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY services/storage-api/requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY services/storage-api/app ./app
COPY services/storage-api/tests ./tests
RUN python -m unittest discover -s tests -v
USER 65532:65532
EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--no-server-header"]
