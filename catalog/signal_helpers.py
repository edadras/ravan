"""Helpers that keep signal definitions compact while producing a full record."""

from contexts import CONTEXTS, TIERS

DETECTOR_DEFAULTS = {
    # feature stays beyond a threshold continuously for min_duration_s
    "sustained": {"window_s": 60, "min_duration_s": 8, "comparison": "absolute_threshold", "cooldown_s": 60},
    # window statistic compared with the person's own session baseline (robust z-score)
    "level_change": {"window_s": 30, "min_duration_s": 0, "comparison": "z_vs_session_baseline", "threshold": 2.0, "cooldown_s": 90},
    # events per minute compared with baseline rate (ratio)
    "rate_change": {"window_s": 60, "min_duration_s": 0, "comparison": "ratio_vs_session_baseline", "threshold": 1.8, "cooldown_s": 120},
    # discrete event(s) with a minimum count inside the window
    "event": {"window_s": 15, "min_duration_s": 0, "comparison": "absolute_threshold", "threshold": 1, "cooldown_s": 30},
    # rhythmic motion: dominant frequency within band with enough power for min_duration_s
    "periodicity": {"window_s": 10, "min_duration_s": 4, "comparison": "absolute_threshold", "threshold": 0.5, "cooldown_s": 60},
    # composition of other signals firing close in time
    "cluster": {"window_s": 20, "min_duration_s": 0, "comparison": "co_occurrence", "threshold": 2, "cooldown_s": 120},
    # lexicon / LLM content flags
    "content": {"window_s": 0, "min_duration_s": 0, "comparison": "content_rule", "threshold": 1, "cooldown_s": 0},
    # technical/quality threshold
    "quality": {"window_s": 5, "min_duration_s": 3, "comparison": "absolute_threshold", "cooldown_s": 60},
    # trend across the session (slope test)
    "trend": {"window_s": 600, "min_duration_s": 0, "comparison": "trend_over_session", "threshold": 0.3, "cooldown_s": 600},
}

GATE_DEFAULTS = {
    "face": {"face_quality": 0.70, "face_in_frame_ratio": 0.9, "illumination": 0.3},
    "pose": {"pose_quality": 0.65},
    "hands": {"hand_quality": 0.60, "hands_visible": True},
    "lower_body": {"pose_quality": 0.65, "lower_body_visible": True},
    "audio": {"audio_quality": 0.60, "audio_snr_db": 15},
    "asr": {"asr_quality": 0.70},
    "turn": {"audio_quality": 0.5, "network_rtt_ms": 400},
    "dyad": {"pose_quality": 0.65, "face_quality": 0.7},
    "none": {},
}

# Labels the system must never attach to any single signal.
GLOBAL_FORBIDDEN = [
    "deception", "lying", "diagnosis", "disorder", "anxiety_disorder", "depression",
    "psychosis", "suicidality_from_nonverbal_only", "personality_trait", "intelligence",
    "substance_use_from_appearance", "sexual_orientation", "political_or_religious_belief",
]


def S(id, group, obs_en, obs_fa, features, contexts, *, tier="observation", detector="level_change",
      gates=("face",), rationale_en="", rationale_fa="", note_en="", note_fa="", forbid=(),
      direction=None, band=None, min_count=None, members=None, speaker_state="any", **overrides):
    """Build one signal record.

    ``overrides`` may set any detector key (threshold, window_s, min_duration_s, ...).
    """
    for c in contexts:
        assert c in CONTEXTS, f"unknown context '{c}' in signal {id}"
    assert tier in TIERS, f"unknown tier '{tier}' in {id}"
    det = dict(DETECTOR_DEFAULTS[detector])
    det["type"] = detector
    det.update(overrides)
    det["speaker_state"] = speaker_state
    if direction:
        det["direction"] = direction
    if band:
        det["frequency_band_hz"] = list(band)
    if min_count is not None:
        det["min_count"] = min_count
    if members:
        det["member_signals"] = list(members)
    gate = {}
    for g in gates:
        gate.update(GATE_DEFAULTS[g])
    return {
        "id": id,
        "group": group,
        "tier": tier,
        "tier_label": {"en": TIERS[tier][0], "fa": TIERS[tier][1]},
        "observation": {"en": obs_en, "fa": obs_fa},
        "features": list(features),
        "detector": det,
        "quality_gates": gate,
        "possible_contexts": [{"key": c, "en": CONTEXTS[c][0], "fa": CONTEXTS[c][1]} for c in contexts],
        "clinical_rationale": {"en": rationale_en, "fa": rationale_fa},
        "clinical_note": {"en": note_en or "Observation only. Interpret with context, baseline and clinician judgment.",
                          "fa": note_fa or "صرفاً مشاهده است. با توجه به زمینه، خط پایه و قضاوت درمانگر تفسیر شود."},
        "forbidden_labels": sorted(set(GLOBAL_FORBIDDEN) | set(forbid)),
        "diagnostic_claim": None,
        "patient_facing": False,
    }
