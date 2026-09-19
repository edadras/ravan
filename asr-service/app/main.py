"""Ravan speech-recognition service.

POST /transcribe  (multipart: audio, language, speaker, t_offset_ms)
  → {"backend": ..., "language": ..., "segments": [{t_start_ms, t_end_ms, text, confidence, is_question}]}

Backends (RAVAN_ASR_BACKEND):
  openai        – OpenAI audio transcription API (RAVAN_ASR_MODEL, e.g. a whisper/transcribe model id)
  faster_whisper– local CTranslate2 Whisper (RAVAN_ASR_MODEL, e.g. "small"); needs ffmpeg/PyAV for webm
  fake          – deterministic output for tests and demos

Audio is processed in memory and discarded. The speaker is known from which participant's
microphone produced the chunk, so no diarization model is needed.

Question detection combines a per-language structural heuristic (questions.py) with a pitch
measurement taken from the audio itself (prosody.py), so a declarative question — one with no
interrogative word, marked only by a rising tail — is still recognised. Each segment reports
`rising_intonation` and the measured `f0_slope_semitones_per_s` alongside `is_question`.
"""

from __future__ import annotations

import logging
import os
import tempfile
from typing import Any

import httpx
from fastapi import FastAPI, File, Form, Header, HTTPException, UploadFile

from . import prosody
from .questions import is_question

log = logging.getLogger("ravan.asr")
app = FastAPI(title="Ravan ASR service", version="0.1.0")
API_TOKEN = os.environ.get("RAVAN_ASR_TOKEN", "")

class FakeBackend:
    name = "fake"

    def transcribe(self, audio: bytes, filename: str, language: str) -> dict[str, Any]:
        n = max(1, len(audio) // 4000)
        return {"language": language, "segments": [{"start": i * 2.0, "end": i * 2.0 + 1.8, "text": {"fa": "متن آزمایشی", "tr": "test metni", "en": "test text"}.get(language, "test text"), "confidence": 0.9} for i in range(min(n, 3))]}


class OpenAIBackend:
    name = "openai"

    def __init__(self) -> None:
        self.key = os.environ["OPENAI_API_KEY"]
        self.model = os.environ.get("RAVAN_ASR_MODEL", "")
        self.base = os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1")
        if not self.model:
            raise RuntimeError("RAVAN_ASR_MODEL must be set")

    def transcribe(self, audio: bytes, filename: str, language: str) -> dict[str, Any]:
        r = httpx.post(f"{self.base}/audio/transcriptions", headers={"Authorization": f"Bearer {self.key}"},
                       files={"file": (filename, audio)}, data={"model": self.model, "language": language, "response_format": "verbose_json", "timestamp_granularities[]": "segment"}, timeout=120)
        r.raise_for_status()
        j = r.json()
        segs = j.get("segments") or [{"start": 0.0, "end": j.get("duration", 0.0), "text": j.get("text", ""), "avg_logprob": -0.2}]
        return {"language": j.get("language", language), "segments": [
            {"start": s["start"], "end": s["end"], "text": s["text"].strip(), "confidence": round(min(1.0, max(0.0, 1.0 + float(s.get("avg_logprob", -0.3)))), 3)} for s in segs]}


class FasterWhisperBackend:
    name = "faster_whisper"

    def __init__(self) -> None:
        from faster_whisper import WhisperModel  # type: ignore

        self.model = WhisperModel(os.environ.get("RAVAN_ASR_MODEL", "small"), device=os.environ.get("RAVAN_ASR_DEVICE", "cpu"), compute_type=os.environ.get("RAVAN_ASR_COMPUTE", "int8"))

    def transcribe(self, audio: bytes, filename: str, language: str) -> dict[str, Any]:
        with tempfile.NamedTemporaryFile(suffix=os.path.splitext(filename)[1] or ".webm", delete=True) as f:
            f.write(audio)
            f.flush()
            segments, info = self.model.transcribe(f.name, language=language, vad_filter=True, beam_size=3)
            out = [{"start": s.start, "end": s.end, "text": s.text.strip(), "confidence": round(min(1.0, max(0.0, 1.0 + s.avg_logprob)), 3)} for s in segments]
        return {"language": info.language or language, "segments": out}


def get_backend():
    name = os.environ.get("RAVAN_ASR_BACKEND", "fake").lower()
    if name == "openai":
        return OpenAIBackend()
    if name == "faster_whisper":
        return FasterWhisperBackend()
    return FakeBackend()


_backend = None


def backend():
    global _backend
    if _backend is None:
        _backend = get_backend()
    return _backend


@app.get("/healthz")
def healthz() -> dict:
    return {"ok": True, "backend": os.environ.get("RAVAN_ASR_BACKEND", "fake")}


@app.post("/transcribe")
async def transcribe(audio: UploadFile = File(...), language: str = Form("fa"), speaker: str = Form("patient"), t_offset_ms: int = Form(0),
                     authorization: str | None = Header(default=None)) -> dict:
    if API_TOKEN and authorization != f"Bearer {API_TOKEN}":
        raise HTTPException(401, "unauthorized")
    if language not in ("fa", "en", "tr"):
        raise HTTPException(422, "unsupported language")
    data = await audio.read()
    if not data:
        raise HTTPException(422, "empty audio")
    try:
        res = backend().transcribe(data, audio.filename or "chunk.webm", language)
    except Exception as exc:  # noqa: BLE001
        log.exception("transcription failed")
        raise HTTPException(502, f"asr backend failed: {exc}") from exc
    lang = res.get("language", language)
    # Only the clinician's utterances anchor a response latency, so the pitch
    # track is only worth computing for those.
    pcm = prosody.decode_pcm(data) if speaker == "clinician" else None
    segments = []
    for s in res["segments"]:
        text = s["text"].strip()
        if not text:
            continue
        rises, slope = prosody.rising(pcm, float(s["start"]), float(s["end"]))
        segments.append({
            "t_start_ms": int(t_offset_ms + s["start"] * 1000), "t_end_ms": int(t_offset_ms + s["end"] * 1000), "text": text,
            "confidence": float(s.get("confidence", 1.0)), "speaker": speaker,
            "is_question": speaker == "clinician" and is_question(text, lang, rises),
            "rising_intonation": rises,
            "f0_slope_semitones_per_s": slope,
        })
    return {"backend": backend().name, "language": lang, "segments": segments}
