# Ravan ASR service

Speech-to-text for session transcription (fa / en / tr) with speaker known per audio track and
question detection.

```
pip install -r requirements.txt
python -m pytest -q
RAVAN_ASR_BACKEND=openai OPENAI_API_KEY=... RAVAN_ASR_MODEL=<transcription model id> uvicorn app.main:app --port 8200
# or local:
pip install faster-whisper && RAVAN_ASR_BACKEND=faster_whisper RAVAN_ASR_MODEL=small uvicorn app.main:app --port 8200
```

Flow: the browser records 5-second chunks of its own microphone (MediaRecorder, webm/opus) →
`POST /api/sessions/{uuid}/asr/chunk` on the backend (requires transcription consent) → this
service → timed segments → `transcript_segments` → analysis service (response latency, content
flags, Q&A analysis) → both clients over WebSocket. Audio is never stored.
