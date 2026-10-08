FROM python:3.12-alpine
WORKDIR /app
COPY collector.py tests.py ./
RUN python -m unittest -v tests
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
CMD ["python", "collector.py"]
