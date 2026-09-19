"""FastAPI entry point.

Endpoints
---------
POST /sessions                      start a session analyser
POST /sessions/{id}/frames          batch of derived feature frames
WS   /ws/sessions/{id}              streaming frames / transcript / control
POST /sessions/{id}/transcript      diarised transcript segment(s)
POST /sessions/{id}/question        clinician question marker
POST /sessions/{id}/control         pause / resume / consent / camera_moved / clinician_mark / topic
GET  /sessions/{id}/events          events so far
GET  /sessions/{id}/baseline        baseline status
POST /sessions/{id}/finish          build the end-of-session report and dispose the analyser
GET  /catalog                       the signal catalog (for UI rendering)
GET  /healthz
"""

from __future__ import annotations

import logging
import os
from typing import Any

from fastapi import FastAPI, Header, HTTPException, WebSocket, WebSocketDisconnect

from . import __version__
from .catalog import load_catalog
from .engine import SessionAnalyzer, WebhookSink
from .schemas import ControlMessage, FrameBatch, QuestionEvent, StartSession, TranscriptSegment

logging.basicConfig(level=os.environ.get("RAVAN_LOG_LEVEL", "INFO"))
app = FastAPI(title="Ravan analysis service", version=__version__)
SESSIONS: dict[str, SessionAnalyzer] = {}
API_TOKEN = os.environ.get("RAVAN_ANALYSIS_TOKEN", "")
WEBHOOK_SECRET = os.environ.get("RAVAN_WEBHOOK_SECRET", "")


def _auth(authorization: str | None) -> None:
    if API_TOKEN and authorization != f"Bearer {API_TOKEN}":
        raise HTTPException(401, "unauthorized")


def _get(session_id: str) -> SessionAnalyzer:
    try:
        return SESSIONS[session_id]
    except KeyError:
        raise HTTPException(404, "unknown session") from None


@app.get("/healthz")
def healthz() -> dict[str, Any]:
    cat = load_catalog()
    return {"ok": True, "version": __version__, "catalog_version": cat.version, "signals": len(cat.signals), "active_sessions": len(SESSIONS)}


@app.get("/catalog")
def catalog(authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    return load_catalog().data


@app.post("/sessions", status_code=201)
def start(cfg: StartSession, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    if cfg.session_id in SESSIONS:
        raise HTTPException(409, "session already started")
    sink = WebhookSink(cfg.webhook_url, WEBHOOK_SECRET) if cfg.webhook_url and WEBHOOK_SECRET else None
    SESSIONS[cfg.session_id] = SessionAnalyzer(cfg, sink=sink)
    return {"session_id": cfg.session_id, "signals_enabled": len(SESSIONS[cfg.session_id].signals)}


@app.post("/sessions/{session_id}/frames")
def frames(session_id: str, batch: FrameBatch, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    an = _get(session_id)
    fired = []
    for f in batch.frames:
        fired.extend(an.ingest(f))
    return {"accepted": len(batch.frames), "events": [e.model_dump() for e in fired]}


@app.post("/sessions/{session_id}/transcript")
def transcript(session_id: str, segs: list[TranscriptSegment], authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    an = _get(session_id)
    fired = []
    for s in segs:
        fired.extend(an.ingest_transcript(s))
    return {"accepted": len(segs), "events": [e.model_dump() for e in fired]}


@app.post("/sessions/{session_id}/question")
def question(session_id: str, q: QuestionEvent, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    _get(session_id).on_question(q)
    return {"ok": True}


@app.post("/sessions/{session_id}/control")
def control(session_id: str, msg: ControlMessage, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    return {"events": [e.model_dump() for e in _get(session_id).control(msg)]}


@app.get("/sessions/{session_id}/events")
def events(session_id: str, since_ms: int = 0, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    an = _get(session_id)
    return {"events": [e.model_dump() for e in an.events if e.t_end_ms >= since_ms]}


@app.get("/sessions/{session_id}/baseline")
def baseline(session_id: str, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    return _get(session_id).baseline.summary()


@app.post("/sessions/{session_id}/finish")
def finish(session_id: str, language: str | None = None, authorization: str | None = Header(default=None)) -> dict:
    _auth(authorization)
    an = SESSIONS.pop(session_id, None)
    if an is None:
        raise HTTPException(404, "unknown session")
    return an.finish(language).model_dump()


@app.websocket("/ws/sessions/{session_id}")
async def ws_ingest(ws: WebSocket, session_id: str) -> None:
    token = ws.query_params.get("token")
    if API_TOKEN and token != API_TOKEN:
        await ws.close(code=4401)
        return
    an = SESSIONS.get(session_id)
    if an is None:
        await ws.close(code=4404)
        return
    await ws.accept()
    try:
        while True:
            msg = await ws.receive_json()
            kind = msg.get("type")
            fired = []
            if kind == "frame":
                from .schemas import FeatureFrame
                fired = an.ingest(FeatureFrame(**msg["data"]))
            elif kind == "transcript":
                fired = an.ingest_transcript(TranscriptSegment(**msg["data"]))
            elif kind == "question":
                an.on_question(QuestionEvent(**msg["data"]))
            elif kind == "control":
                fired = an.control(ControlMessage(**msg["data"]))
            if fired:
                await ws.send_json({"type": "events", "events": [e.model_dump() for e in fired]})
    except WebSocketDisconnect:
        return
