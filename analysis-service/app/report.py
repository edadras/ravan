"""End-of-session report: structured aggregates plus a guarded LLM draft."""

from __future__ import annotations

import json

from .llm import SYSTEM_PROMPT, get_provider, guard
from .schemas import BehaviorEvent, SessionReport, TranscriptSegment

DISCLAIMER = {
    "en": "This report lists observable changes relative to the patient's own baseline in this session. It contains no diagnosis, no emotion classification and no risk score. Every item requires clinician review (Accept / Edit / Reject) before it becomes part of any record.",
    "fa": "این گزارش تغییرات قابل مشاهده نسبت به خط پایه خود بیمار در همین جلسه را فهرست می‌کند. هیچ تشخیص، طبقه‌بندی هیجان یا امتیاز خطری ندارد. هر مورد پیش از ورود به پرونده باید توسط درمانگر بازبینی شود (تأیید / ویرایش / رد).",
}


def _fmt_t(ms: int) -> str:
    s = ms // 1000
    return f"{s // 60:02d}:{s % 60:02d}"


def build_report(session_id: str, duration_ms: int, events: list[BehaviorEvent], transcript: list[TranscriptSegment],
                 baseline_summary: dict, quality_summary: dict, language: str = "fa") -> SessionReport:
    speaking = {"patient": 0, "clinician": 0, "unknown": 0}
    for seg in transcript:
        speaking[seg.speaker] += seg.t_end_ms - seg.t_start_ms
    spoken = sum(speaking.values())
    silence = max(duration_ms - spoken, 0)

    by_tier: dict[str, int] = {}
    for e in events:
        by_tier[e.tier] = by_tier.get(e.tier, 0) + 1

    change_events = [e for e in events if e.tier in ("change", "observation", "medical")]
    strongest = sorted(change_events, key=lambda e: (abs(e.z_score or 0) * e.confidence), reverse=True)[:15]
    clusters = [e for e in events if e.tier == "cluster"]
    safety = [e for e in events if e.tier == "safety"]

    by_group: dict[str, int] = {}
    for e in change_events:
        by_group[e.group] = by_group.get(e.group, 0) + 1

    # topic association: which topics/questions had the most clustered change
    topic_counts: dict[str, int] = {}
    for e in clusters:
        key = e.context.get("preceding_question_text") or e.context.get("topic_segment") or "—"
        topic_counts[key] = topic_counts.get(key, 0) + 1

    speech_events = [e for e in events if e.group in ("speech_prosody", "voice_quality", "turn_taking", "speech_fluency_language")]

    def ev(e: BehaviorEvent) -> dict:
        return {"t": _fmt_t(e.t_start_ms), "t_start_ms": e.t_start_ms, "signal_id": e.signal_id, "group": e.group, "tier": e.tier,
                "observation": e.observation, "observed": e.observed_value, "baseline": e.baseline_value, "z": e.z_score,
                "confidence": e.confidence, "quality": e.quality, "possible_contexts": e.possible_contexts,
                "context": e.context, "members": e.member_events, "clinician_status": e.clinician_status, "event_id": e.id}

    payload = {
        "duration_min": round(duration_ms / 60000, 1),
        "patient_speech_share": round(100 * speaking["patient"] / spoken, 1) if spoken else 0,
        "n_change_events": len(change_events),
        "n_clusters": len(clusters),
        "strongest": [{"t": _fmt_t(e.t_start_ms), "observation_en": e.observation["en"], "observation_fa": e.observation["fa"],
                       "observed": e.observed_value, "baseline": e.baseline_value,
                       "quality": {k: v for k, v in e.quality.items() if isinstance(v, (int, float))},
                       "contexts_en": ", ".join(c["en"] for c in e.possible_contexts[:4]),
                       "contexts_fa": "، ".join(c["fa"] for c in e.possible_contexts[:4])} for e in strongest],
        "safety": [{"t": _fmt_t(e.t_start_ms), "text": e.context.get("transcript_text", "")} for e in safety],
    }
    provider = get_provider()
    raw = provider.complete(SYSTEM_PROMPT, json.dumps(payload, ensure_ascii=False), language)
    clean, removed = guard(raw)

    return SessionReport(
        session_id=session_id,
        duration_ms=duration_ms,
        conversation={"clinician_speaking_ms": speaking["clinician"], "patient_speaking_ms": speaking["patient"],
                      "silence_ms": silence, "turns": len(transcript),
                      "questions": sum(1 for s in transcript if s.is_question and s.speaker == "clinician")},
        baseline=baseline_summary,
        behavioral_observations={"notable_change_events": len(change_events), "by_group": by_group,
                                 "clusters": len(clusters), "topics_with_most_clusters": sorted(topic_counts.items(), key=lambda x: -x[1])[:5]},
        speech={"events": [ev(e) for e in speech_events][:50]},
        quality=quality_summary,
        events_by_tier=by_tier,
        strongest_changes=[ev(e) for e in strongest],
        clusters=[ev(e) for e in clusters],
        safety_flags=[ev(e) for e in safety],
        ai_draft_summary={"language": language, "text": clean, "guardrail_removed": removed,
                          "provider": type(provider).__name__, "status": "draft_pending_clinician_review"},
        disclaimer=DISCLAIMER,
    )
