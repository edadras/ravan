# Ravan analysis service — observational behaviour signals from derived numbers.
# It never receives pixels or audio: only the features the patient's browser
# computes on device.
FROM python:3.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY analysis-service/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt

COPY analysis-service/app /app/app
COPY catalog/signal_catalog.json catalog/feature_dictionary.json /app/catalog/

ENV RAVAN_CATALOG_PATH=/app/catalog/signal_catalog.json \
    RAVAN_FEATURE_DICT_PATH=/app/catalog/feature_dictionary.json \
    RAVAN_LLM_PROVIDER=none \
    PYTHONUNBUFFERED=1

RUN useradd --create-home --uid 10002 ravan
USER ravan

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8100/healthz || exit 1

EXPOSE 8100
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100", "--proxy-headers", "--forwarded-allow-ips", "*"]
