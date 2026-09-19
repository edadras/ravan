FROM python:3.11-slim
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY asr-service/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r requirements.txt
# uncomment for the local backend (large download):
# RUN pip install --no-cache-dir faster-whisper
COPY asr-service/app /app/app
ENV RAVAN_ASR_BACKEND=openai
EXPOSE 8200
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8200"]
