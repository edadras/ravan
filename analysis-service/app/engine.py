"""Session analysis engine: ingest → gates → baseline → detectors → fusion → events."""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import uuid
from collections import deque
from typing import Callable

import httpx

from .baseline import Baseline
from .buffers import FeatureBuffer
from .catalog import Catalog, load_catalog
from .content import analyze as analyze_content
from .detectors import DETECTORS, Candidate, DetectorContext
from .fusion import ClusterFuser
from .report import build_report
from .schemas import BehaviorEvent, ControlMessage, FeatureFrame, QuestionEvent, SessionReport, StartSession, TranscriptSegment

log = logging.getLogger("ravan.engine")

CLINICIAN_PROMPT = {
    "en": "Consider whether this change is clinically relevant; ask for context if appropriate.",
    "fa": "بررسی کنید آیا این تغییر از نظر بالینی مرتبط است؛ در صورت مناسب بودن، زمینه را بپرسید.",
    "tr": "Bu değişimin klinik olarak anlamlı olup olmadığını değerlendirin; uygunsa bağlamı sorun.",
}
QUALITY_KEYS = ("face_quality", "pose_quality", "hand_quality", "audio_quality", "asr_quality")
TICK_S = 1.0


class SessionAnalyzer:
    def __init__(self, cfg: StartSession, catalog: Catalog | None = None, sink: Callable[[BehaviorEvent], None] | None = None):
        self.cfg = cfg
        self.catalog = catalog or load_catalog()
        self.sink = sink
        self.units = {k: v["unit"] for k, v in self._feature_units().items()}
        self.baseline = Baseline(window_s=cfg.baseline_window_s or self.catalog.baseline_window_s)
        self.buffers: dict[str, FeatureBuffer] = {}
        self.quality: dict[str, float | bool | int] = {}
        self.events: list[BehaviorEvent] = []
        self.recent: deque[BehaviorEvent] = deque(maxlen=400)
        self.transcript: list[TranscriptSegment] = []
        self.last_question: dict | None = None
        self.topic_segment: str | None = None
        self.paused = False
        self.consent_withdrawn = False
        self.network_rtt_ms = cfg.network_rtt_ms
        self.now_ms = 0
        self._last_tick = -1.0
        self._cooldown: dict[str, float] = {}
        self._quality_history: dict[str, list[float]] = {}
        self.signals = [s for s in self.catalog.detectable() if self._enabled(s)]
        self.fuser = ClusterFuser([s for s in self.catalog.clusters() if self._enabled(s)])
        self.speaker_state = "any"

    # ------------------------------------------------------------------ config helpers
    def _enabled(self, s: dict) -> bool:
        if s["group"] in self.cfg.disabled_groups:
            return False
        if self.cfg.enabled_signals is not None:
            return s["id"] in self.cfg.enabled_signals
        return True

    def _feature_units(self) -> dict:
        path = os.path.join(os.path.dirname(__file__), "..", "..", "catalog", "feature_dictionary.json")
        alt = os.environ.get("RAVAN_FEATURE_DICT_PATH", "/app/catalog/feature_dictionary.json")
        for p in (os.environ.get("RAVAN_FEATURE_DICT_PATH", ""), path, alt):
            if p and os.path.exists(p):
                with open(p, encoding="utf-8") as fh:
                    return json.load(fh)["derived_features"]
        return {}

    # ------------------------------------------------------------------ ingest
    def ingest(self, frame: FeatureFrame) -> list[BehaviorEvent]:
        if self.consent_withdrawn or self.paused:
            return []
        self.now_ms = max(self.now_ms, frame.t_ms)
        t = frame.t_ms / 1000.0
        if frame.speaker_state != "any":
            self.speaker_state = frame.speaker_state
        state = frame.speaker_state if frame.speaker_state != "any" else self.speaker_state
        for k, v in frame.quality.items():
            self.quality[k] = v
            if isinstance(v, (int, float)) and not isinstance(v, bool):
                self._quality_history.setdefault(k, []).append(float(v))
                self._add(t, k, float(v), state, quality_ok=True, is_event=False)
        quality_ok = self._source_quality_ok(frame.source)
        for k, v in frame.features.items():
            unit = self.units.get(k)
            if unit == "event":
                if v is True or (isinstance(v, (int, float)) and not isinstance(v, bool) and v > 0):
                    self._add(t, k, 1.0, state, quality_ok, is_event=True)
            elif unit == "cat":
                if v not in (None, "none", "", False, 0):
                    self._add(t, k, 1.0, state, quality_ok, is_event=True)
            elif isinstance(v, bool):
                self._add(t, k, 1.0 if v else 0.0, state, quality_ok, is_event=False)
            elif isinstance(v, (int, float)):
                self._add(t, k, float(v), state, quality_ok, is_event=False)
        return self._maybe_tick(t)

    def _add(self, t: float, feature: str, value: float, state: str, quality_ok: bool, is_event: bool) -> None:
        buf = self.buffers.setdefault(feature, FeatureBuffer())
        if is_event:
            buf.add_event(t, state)
            if quality_ok:
                self.baseline.observe_event(t, feature, state)
        else:
            buf.add(t, value, state)
            self.baseline.observe(t, feature, value, state, quality_ok)

    def _source_quality_ok(self, source: str) -> bool:
        key = {"face": "face_quality", "pose": "pose_quality", "hands": "hand_quality", "audio": "audio_quality", "asr": "asr_quality"}.get(source)
        if key is None:
            return True
        v = self.quality.get(key)
        return v is None or float(v) >= 0.6

    def ingest_transcript(self, seg: TranscriptSegment) -> list[BehaviorEvent]:
        if self.consent_withdrawn:
            return []
        self.transcript.append(seg)
        self.now_ms = max(self.now_ms, seg.t_end_ms)
        out: list[BehaviorEvent] = []
        if seg.speaker == "clinician" and seg.is_question:
            self.on_question(QuestionEvent(t_ms=seg.t_end_ms, question_id=str(uuid.uuid4()), text=seg.text, topic=self.topic_segment))
            return out
        if seg.speaker != "patient" or self.paused:
            return out
        answer_to_open_q = bool(self.last_question and seg.t_start_ms - self.last_question["t_ms"] < 15000)
        cf = analyze_content(seg.text, seg.language, answer_to_open_q)
        t = seg.t_end_ms / 1000.0
        state = "patient_speaking"
        feats = {"negative_lexicon_ratio": cf.negative_ratio, "positive_lexicon_ratio": cf.positive_ratio,
                 "absolutist_ratio": cf.absolutist_ratio, "hedge_ratio": cf.hedge_ratio,
                 "first_person_ratio": cf.first_person_ratio, "word_count": cf.word_count,
                 "utterance_word_count_mean": cf.word_count, "utterance_duration": (seg.t_end_ms - seg.t_start_ms) / 1000.0}
        feats.update({k: v for k, v in seg.features.items() if isinstance(v, (int, float))})
        for k, v in feats.items():
            self._add(t, k, float(v), state, seg.confidence >= 0.7, is_event=False)
        if cf.minimal_response:
            self._add(t, "minimal_response_flag", 1.0, state, True, is_event=True)
        if self.last_question and answer_to_open_q:
            latency = seg.t_start_ms - self.last_question["t_ms"] - self.network_rtt_ms
            self._add(t, "response_latency_ms", max(0.0, latency), state, True, is_event=False)
        if cf.safety_hits:
            sig = self.catalog.signal("safety_relevant_phrase")
            ev = self._make_event(sig, Candidate(sig["id"], seg.t_start_ms / 1000, seg.t_end_ms / 1000, None, None, None, None, None, 0.9, "risk_lexicon_hit"),
                                  extra_context={"transcript_text": seg.text, "matched": cf.safety_hits})
            out.append(ev)
        out.extend(self._maybe_tick(t))
        return out

    def on_question(self, q: QuestionEvent) -> None:
        self.last_question = q.model_dump()
        self._add(q.t_ms / 1000.0, "question_event", 1.0, "patient_listening", True, is_event=True)
        if q.topic:
            self.topic_segment = q.topic

    def control(self, msg: ControlMessage) -> list[BehaviorEvent]:
        self.now_ms = max(self.now_ms, msg.t_ms)
        sig_map = {"analysis_pause": "analysis_paused_by_patient", "analysis_resume": "analysis_resumed_by_patient",
                   "consent_withdraw": "consent_withdrawn", "camera_moved": "camera_moved", "clinician_mark": "clinician_marked_segment"}
        if msg.action == "analysis_pause":
            self.paused = True
        elif msg.action == "analysis_resume":
            self.paused = False
        elif msg.action == "consent_withdraw":
            self.consent_withdrawn = True
            self.paused = True
        elif msg.action == "camera_moved":
            self.baseline.reset_geometry()
        elif msg.action == "topic_segment":
            self.topic_segment = msg.payload.get("topic")
            return []
        sig = self.catalog.signal(sig_map[msg.action])
        ev = self._make_event(sig, Candidate(sig["id"], msg.t_ms / 1000, msg.t_ms / 1000, None, None, None, None, None, 1.0, ""), extra_context=msg.payload)
        return [ev]

    # ------------------------------------------------------------------ evaluation
    def _maybe_tick(self, t: float) -> list[BehaviorEvent]:
        if t - self._last_tick < TICK_S:
            return []
        self._last_tick = t
        return self.evaluate(t)

    def _gates_pass(self, signal: dict) -> tuple[bool, float]:
        score = 1.0
        for k, thr in signal["quality_gates"].items():
            v = self.quality.get(k)
            if v is None:
                continue  # gate feature not reported → do not block, but do not boost either
            if isinstance(thr, bool):
                if bool(v) != thr:
                    return False, 0.0
            elif k in ("network_rtt_ms",):
                if float(v) > thr:
                    return False, 0.0
            else:
                if float(v) < thr:
                    return False, 0.0
                score = min(score, float(v))
        return True, score

    def evaluate(self, t: float) -> list[BehaviorEvent]:
        if self.paused or self.consent_withdrawn:
            return []
        fired: list[BehaviorEvent] = []
        for sig in self.signals:
            det = sig["detector"]
            if t - self._cooldown.get(sig["id"], -1e9) < det["cooldown_s"]:
                continue
            ok, qscore = self._gates_pass(sig)
            if not ok:
                continue
            fn = DETECTORS.get(det["type"])
            if fn is None:
                continue
            try:
                cand = fn(sig, DetectorContext(t, self.buffers, self.baseline, qscore, self.units))
            except Exception as exc:  # a broken detector must never take the session down
                log.exception("detector %s failed: %s", sig["id"], exc)
                continue
            if cand is None:
                continue
            self._cooldown[sig["id"]] = t
            fired.append(self._make_event(sig, cand))
        # fusion
        for cl in self.fuser.evaluate(int(t * 1000), list(self.recent), self.last_question):
            sig = self.catalog.signal(cl.signal_id)
            ev = self._make_event(sig, Candidate(cl.signal_id, cl.t_start_ms / 1000, cl.t_end_ms / 1000, float(len(cl.members)), None, None, None, None, cl.confidence, ""),
                                  member_ids=[m.id for m in cl.members])
            ev.context["member_signals"] = [m.signal_id for m in cl.members]
            fired.append(ev)
        return fired

    def _make_event(self, sig: dict, c: Candidate, member_ids: list[str] | None = None, extra_context: dict | None = None) -> BehaviorEvent:
        ctx: dict = {"speaker": self.speaker_state, "topic_segment": self.topic_segment, "feature": c.feature, **c.extra}
        if self.last_question:
            # link to the last question by detection time (t_end); windowed detectors start before the change
            after = (c.t_end * 1000 - self.last_question["t_ms"]) / 1000.0
            if -2 <= after <= 90:
                ctx.update({"preceding_question_id": self.last_question["question_id"],
                            "preceding_question_text": self.last_question["text"],
                            "seconds_after_question": round(after, 1)})
        if extra_context:
            ctx.update(extra_context)
        ev = BehaviorEvent(
            id=str(uuid.uuid4()), session_id=self.cfg.session_id, signal_id=sig["id"], group=sig["group"], tier=sig["tier"],
            t_start_ms=int(c.t_start * 1000), t_end_ms=int(c.t_end * 1000), observation=sig["observation"],
            baseline_value=_r(c.baseline), observed_value=_r(c.observed), delta=_r(c.delta), delta_ratio=_r(c.delta_ratio),
            z_score=_r(c.z), unit=c.unit, confidence=c.confidence,
            quality={k: self.quality[k] for k in QUALITY_KEYS + ("network_rtt_ms",) if k in self.quality},
            context=ctx, possible_contexts=[{"key": p["key"], "en": p["en"], "fa": p["fa"], "tr": p["tr"]} for p in sig["possible_contexts"]],
            clinical_rationale=sig.get("clinical_rationale", {}), clinical_note=sig["clinical_note"], clinician_prompt=CLINICIAN_PROMPT,
            member_events=member_ids or [],
        )
        self.events.append(ev)
        self.recent.append(ev)
        if self.sink:
            try:
                self.sink(ev)
            except Exception as exc:
                log.warning("sink failed: %s", exc)
        return ev

    # ------------------------------------------------------------------ finish
    def finish(self, language: str | None = None) -> SessionReport:
        q_summary = {k: {"mean": round(sum(v) / len(v), 3), "min": round(min(v), 3), "n": len(v)} for k, v in self._quality_history.items() if v}
        return build_report(self.cfg.session_id, self.now_ms, self.events, self.transcript, self.baseline.summary(), q_summary, language or self.cfg.language)


def _r(v):
    if v is None:
        return None
    try:
        if v != v or v in (float("inf"), float("-inf")):
            return None
    except TypeError:
        return None
    return round(float(v), 4)


class WebhookSink:
    """Signed delivery of events to the Laravel backend."""

    def __init__(self, url: str, secret: str):
        self.url = url
        self.secret = secret.encode()
        self.client = httpx.Client(timeout=5.0)

    def __call__(self, ev: BehaviorEvent) -> None:
        body = ev.model_dump_json().encode()
        sig = hmac.new(self.secret, body, hashlib.sha256).hexdigest()
        self.client.post(self.url, content=body, headers={"Content-Type": "application/json", "X-Ravan-Signature": sig})
