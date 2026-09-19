# Ravan analysis service

FastAPI service that turns on-device derived features into clinician-facing **observations**.

```
pip install -r requirements.txt
python -m pytest -q            # 15 synthetic tests (baseline, detectors, clusters, consent, safety flag, guardrail)
uvicorn app.main:app --port 8100
```

| Module | Role |
|---|---|
| `catalog.py` | loads `catalog/signal_catalog.json` |
| `schemas.py` | ingest/output contracts (`FeatureFrame`, `TranscriptSegment`, `ControlMessage`, `BehaviorEvent`, `SessionReport`) |
| `baseline.py` | robust per-session, per-speaker-state baseline (median / MAD); geometry reset after `camera_moved` |
| `buffers.py` | windowed statistics, sustained-duration, dominant-frequency |
| `detectors.py` | `level_change`, `rate_change`, `sustained`, `event`, `periodicity`, `quality`, `trend` |
| `fusion.py` | multimodal clusters (≥ N member signals from ≥ 2 groups) |
| `content.py` | transcript-only lexicon flags incl. explicit safety phrases (no risk score) |
| `engine.py` | `SessionAnalyzer`: gates → baseline → detectors → fusion → events; HMAC webhook sink |
| `report.py`, `llm.py` | structured report + guarded LLM draft (`RAVAN_LLM_PROVIDER=none|anthropic|openai`) |
| `main.py` | REST + WebSocket API |

Environment: `RAVAN_ANALYSIS_TOKEN`, `RAVAN_WEBHOOK_SECRET`, `RAVAN_CATALOG_PATH`, `RAVAN_FEATURE_DICT_PATH`, `RAVAN_LLM_PROVIDER`, `RAVAN_LLM_MODEL`.
