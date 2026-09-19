# Ravan speech-recognition service.
#
# Build with INSTALL_FASTER_WHISPER=true (the default) for a fully local,
# nothing-leaves-the-server transcription backend. Set it to false for a much
# smaller image when you only ever use the `openai` backend.
FROM python:3.11-slim

ARG INSTALL_FASTER_WHISPER=true

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY asr-service/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt \
    && if [ "$INSTALL_FASTER_WHISPER" = "true" ]; then \
         pip install --no-cache-dir "faster-whisper>=1.0"; \
       fi

COPY asr-service/app /app/app

# Models are downloaded on first use into this volume, not baked into the image.
ENV RAVAN_ASR_BACKEND=faster_whisper \
    HF_HOME=/models \
    PYTHONUNBUFFERED=1
VOLUME ["/models"]

RUN useradd --create-home --uid 10001 ravan && mkdir -p /models && chown ravan /models
USER ravan

HEALTHCHECK --interval=30s --timeout=5s --start-period=120s --retries=5 \
    CMD curl -fsS http://127.0.0.1:8200/healthz || exit 1

EXPOSE 8200
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8200"]
