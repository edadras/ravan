"""Synthetic end-to-end tests: baseline → change → event → cluster → report, and guardrails."""

import os
import random
import sys
import time

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app.baseline import Baseline  # noqa: E402
from app.catalog import load_catalog  # noqa: E402
from app.content import analyze  # noqa: E402
from app.engine import SessionAnalyzer  # noqa: E402
from app.llm import guard  # noqa: E402
from app.schemas import ControlMessage, FeatureFrame, QuestionEvent, StartSession, TranscriptSegment  # noqa: E402

QUALITY = {"face_quality": 0.92, "pose_quality": 0.9, "hand_quality": 0.85, "audio_quality": 0.9, "asr_quality": 0.9,
           "face_in_frame_ratio": 1.0, "illumination": 0.7, "hands_visible": True, "network_rtt_ms": 80}


def make_analyzer(**kw) -> SessionAnalyzer:
    cfg = StartSession(session_id="s1", patient_ref="p", clinician_ref="c", baseline_window_s=60, **kw)
    return SessionAnalyzer(cfg)


def feed_baseline(an: SessionAnalyzer, seconds: int = 70, fps: int = 5, rng: random.Random | None = None):
    rng = rng or random.Random(1)
    for i in range(seconds * fps):
        t_ms = int(i * 1000 / fps)
        an.ingest(FeatureFrame(t_ms=t_ms, source="face", speaker_state="patient_listening",
                               features={"head_yaw": rng.gauss(0, 3), "head_pitch": rng.gauss(0, 3), "blink_event": i % 15 == 0,
                                         "au_activity_total": rng.gauss(2.0, 0.3), "head_motion_energy": rng.gauss(0.2, 0.05)},
                               quality=QUALITY))
        an.ingest(FeatureFrame(t_ms=t_ms, source="pose", speaker_state="patient_listening",
                               features={"torso_angle": rng.gauss(5, 1.5), "pose_change_event": i % 40 == 0,
                                         "motion_energy_total": rng.gauss(0.3, 0.05)}, quality=QUALITY))


def test_catalog_loads_and_is_consistent():
    cat = load_catalog()
    assert len(cat.signals) > 250
    assert all(s["diagnostic_claim"] is None for s in cat.signals.values())
    assert all(s["patient_facing"] is False for s in cat.signals.values())
    # every cluster member exists
    for c in cat.clusters():
        for m in c["detector"]["member_signals"]:
            assert m == "*" or m in cat.signals


def test_baseline_is_robust_to_outliers():
    b = Baseline(window_s=10)
    for i in range(100):
        b.observe(i * 0.1, "x", 10.0 + (i % 3) * 0.1, "any", True)
    b.observe(9.9, "x", 1000.0, "any", True)  # single outlier
    b.observe(10.5, "x", 10.0, "any", True)  # crosses window → freeze
    fb = b.get("x")
    assert fb is not None and abs(fb.median - 10.1) < 0.2
    assert fb.sigma < 1.0  # outlier did not inflate spread


def test_no_change_events_before_baseline_ready():
    an = make_analyzer()
    feed_baseline(an, seconds=30)
    assert not an.baseline.ready
    assert not [e for e in an.events if e.tier == "change"]


def test_sustained_head_turn_fires_once_per_cooldown():
    an = make_analyzer()
    feed_baseline(an, seconds=70)
    t0 = 71_000
    for i in range(60):  # 12 s of yaw = 40°
        an.ingest(FeatureFrame(t_ms=t0 + i * 200, source="face", features={"head_yaw": 40.0}, quality=QUALITY))
    ids = [e.signal_id for e in an.events]
    assert ids.count("head_turn_away_sustained") == 1
    ev = next(e for e in an.events if e.signal_id == "head_turn_away_sustained")
    assert ev.diagnostic_claim is None
    assert any(p["key"] == "other_person" for p in ev.possible_contexts)
    assert ev.observation["fa"] and ev.observation["tr"] and ev.observation["en"]
    assert all(p["tr"] for p in ev.possible_contexts)
    assert ev.clinical_note["tr"]


def test_posture_change_rate_and_cluster_around_question():
    an = make_analyzer()
    feed_baseline(an, seconds=70)
    an.on_question(QuestionEvent(t_ms=71_000, question_id="q1", text="رابطه شما با خانواده چطور است؟", topic="family"))
    rng = random.Random(2)
    t = 71_000
    for i in range(300):  # 60 s of frequent posture changes, turned-away head, reduced facial activity
        t = 71_000 + i * 200
        an.ingest(FeatureFrame(t_ms=t, source="pose", speaker_state="patient_speaking",
                               features={"torso_angle": rng.gauss(5, 1.5), "pose_change_event": i % 5 == 0, "motion_energy_total": 0.9}, quality=QUALITY))
        an.ingest(FeatureFrame(t_ms=t, source="face", speaker_state="patient_speaking",
                               features={"head_yaw": 35.0, "au_activity_total": 0.4, "head_motion_energy": 0.2}, quality=QUALITY))
    ids = {e.signal_id for e in an.events}
    assert "posture_change_rate_increase" in ids
    assert "head_turn_away_sustained" in ids
    change = next(e for e in an.events if e.signal_id == "posture_change_rate_increase")
    assert change.baseline_value is not None and change.observed_value > change.baseline_value
    assert change.context.get("preceding_question_text", "").startswith("رابطه")
    clusters = [e for e in an.events if e.tier == "cluster"]
    assert clusters, "expected a multimodal cluster"
    assert all(len(c.member_events) >= 2 for c in clusters)


def test_quality_gate_suppresses_face_signals():
    an = make_analyzer()
    feed_baseline(an, seconds=70)
    bad = dict(QUALITY, face_quality=0.2)
    for i in range(60):
        an.ingest(FeatureFrame(t_ms=71_000 + i * 200, source="face", features={"head_yaw": 40.0}, quality=bad))
    assert "head_turn_away_sustained" not in {e.signal_id for e in an.events}


def test_pause_and_consent_stop_processing():
    an = make_analyzer()
    feed_baseline(an, seconds=70)
    evs = an.control(ControlMessage(t_ms=71_000, action="analysis_pause"))
    assert evs[0].signal_id == "analysis_paused_by_patient"
    for i in range(60):
        an.ingest(FeatureFrame(t_ms=71_100 + i * 200, source="face", features={"head_yaw": 40.0}, quality=QUALITY))
    assert "head_turn_away_sustained" not in {e.signal_id for e in an.events}
    an.control(ControlMessage(t_ms=90_000, action="consent_withdraw"))
    assert an.consent_withdrawn


def test_safety_phrase_flag_comes_only_from_transcript():
    an = make_analyzer()
    evs = an.ingest_transcript(TranscriptSegment(t_start_ms=1000, t_end_ms=3000, speaker="patient", text="دیگه نمی‌خوام زنده باشم", language="fa"))
    flags = [e for e in evs if e.tier == "safety"]
    assert len(flags) == 1
    assert flags[0].context["transcript_text"] == "دیگه نمی‌خوام زنده باشم"
    assert flags[0].z_score is None  # no score, just the words
    cf = analyze("I feel fine today", "en")
    assert cf.safety_hits == []
    tr = an.ingest_transcript(TranscriptSegment(t_start_ms=4000, t_end_ms=6000, speaker="patient", text="Artık yaşamak istemiyorum", language="tr"))
    assert [e for e in tr if e.tier == "safety"]
    assert analyze("Bugün İyiyim, hiçbir şey yok", "tr").safety_hits == []
    assert analyze("Hiç", "tr", is_answer_to_open_question=True).minimal_response


def test_turkish_report_draft_and_disclaimer():
    an = make_analyzer(language="tr")
    feed_baseline(an, seconds=70)
    for i in range(60):
        an.ingest(FeatureFrame(t_ms=71_000 + i * 200, source="face", features={"head_yaw": 40.0}, quality=QUALITY))
    rep = an.finish("tr")
    assert "Klinik bir değerlendirme değildir" in rep.ai_draft_summary["text"]
    assert rep.disclaimer["tr"]
    clean, removed = guard("Hasta açıkça depresif görünüyor.")
    assert removed


def test_response_latency_change_from_transcript():
    an = make_analyzer()
    # baseline: fast answers (1 s) for 70 s
    t = 0
    for i in range(10):
        an.ingest_transcript(TranscriptSegment(t_start_ms=t, t_end_ms=t + 2000, speaker="clinician", text="چطوری؟", is_question=True))
        an.ingest_transcript(TranscriptSegment(t_start_ms=t + 3000, t_end_ms=t + 6000, speaker="patient", text="خوبم ممنون امروز روز خوبی بود"))
        t += 7000
    # keep the baseline window ticking with any frames
    for i in range(10):
        an.ingest(FeatureFrame(t_ms=t + i * 100, source="quality", quality=QUALITY))
    assert an.baseline.ready
    # now a 6 s latency
    an.ingest_transcript(TranscriptSegment(t_start_ms=t, t_end_ms=t + 2000, speaker="clinician", text="رابطه شما با خانواده چطور است؟", is_question=True))
    for i in range(0, 20):
        an.ingest(FeatureFrame(t_ms=t + 2000 + i * 100, source="quality", quality=QUALITY))
    evs = an.ingest_transcript(TranscriptSegment(t_start_ms=t + 8000, t_end_ms=t + 9000, speaker="patient", text="هیچی"))
    ids = {e.signal_id for e in an.events}
    assert "response_latency_increase" in ids
    assert "minimal_response_to_open_question" in ids


def test_report_has_no_forbidden_claims_and_is_bilingual():
    an = make_analyzer()
    feed_baseline(an, seconds=70)
    an.ingest_transcript(TranscriptSegment(t_start_ms=0, t_end_ms=10_000, speaker="clinician", text="سلام"))
    an.ingest_transcript(TranscriptSegment(t_start_ms=10_000, t_end_ms=30_000, speaker="patient", text="سلام خوبم"))
    for i in range(60):
        an.ingest(FeatureFrame(t_ms=71_000 + i * 200, source="face", features={"head_yaw": 40.0}, quality=QUALITY))
    rep = an.finish("fa")
    assert rep.diagnostic_claim is None
    assert "Not a clinical assessment" in rep.ai_draft_summary["text"] or "ارزیابی بالینی نیست" in rep.ai_draft_summary["text"]
    assert rep.ai_draft_summary["status"] == "draft_pending_clinician_review"
    assert rep.conversation["patient_speaking_ms"] == 20_000
    assert rep.disclaimer["fa"]


@pytest.mark.parametrize("text,should_remove", [
    ("The patient is clearly anxious about work.", True),
    ("Posture changes increased 2.1x versus baseline after 12:27.", False),
    ("بیمار افسرده است.", True),
    ("در ۹۰ ثانیه اخیر نرخ تغییر وضعیت بدن ۲٫۱ برابر شد.", False),
    ("This suggests the patient is lying.", True),
])
def test_guardrail(text, should_remove):
    clean, removed = guard(text)
    assert bool(removed) == should_remove
    if should_remove:
        assert text not in clean


# --------------------------------------------------------------- ingest tokens
def _session_token(secret: str, session_id: str, expires: int) -> str:
    import base64
    import hashlib
    import hmac

    sig = base64.urlsafe_b64encode(
        hmac.new(secret.encode(), f"{session_id}.{expires}".encode(), hashlib.sha256).digest()
    ).decode().rstrip("=")
    return f"v1.{session_id}.{expires}.{sig}"


def test_ingest_token_is_bound_to_one_session_and_expires(monkeypatch):
    """The browser gets a credential for its own session, not the service secret."""
    from app import main

    monkeypatch.setattr(main, "API_TOKEN", "service-secret")
    now = int(time.time())

    good = _session_token("service-secret", "session-a", now + 600)
    assert main.verify_ingest_token(good, "session-a")

    # Not reusable against another session...
    assert not main.verify_ingest_token(good, "session-b")
    # ...nor after it expires...
    assert not main.verify_ingest_token(_session_token("service-secret", "session-a", now - 1), "session-a")
    # ...nor forged with a different secret.
    assert not main.verify_ingest_token(_session_token("guessed", "session-a", now + 600), "session-a")
    # The service token itself still works, for backend-to-service calls.
    assert main.verify_ingest_token("service-secret", "session-a")
    assert not main.verify_ingest_token(None, "session-a")
    assert not main.verify_ingest_token("v1.session-a.nonsense.sig", "session-a")


def test_idle_sessions_are_evicted(monkeypatch):
    """An abandoned call must not keep its analyser alive for the process's lifetime."""
    from app import main

    monkeypatch.setattr(main, "SESSION_TTL_S", 60.0)
    main.SESSIONS["stale"] = object()
    main.LAST_SEEN["stale"] = time.time() - 120
    main.SESSIONS["fresh"] = object()
    main.LAST_SEEN["fresh"] = time.time()

    assert main._sweep() == 1
    assert "stale" not in main.SESSIONS
    assert "fresh" in main.SESSIONS
    main.SESSIONS.pop("fresh")
    main.LAST_SEEN.pop("fresh")
