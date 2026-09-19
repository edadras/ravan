FROM python:3.11-slim
WORKDIR /app
COPY analysis-service/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt
COPY analysis-service/app /app/app
COPY catalog/signal_catalog.json catalog/feature_dictionary.json /app/catalog/
ENV RAVAN_CATALOG_PATH=/app/catalog/signal_catalog.json \
    RAVAN_FEATURE_DICT_PATH=/app/catalog/feature_dictionary.json \
    RAVAN_LLM_PROVIDER=none
EXPOSE 8100
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100"]
