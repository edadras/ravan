"""Pydantic models for the ingest and output contracts."""

from __future__ import annotations

from typing import Any, Literal, Optional

from pydantic import BaseModel, Field

SpeakerState = Literal["patient_speaking", "patient_listening", "silence", "any"]
Source = Literal["face", "pose", "hands", "audio", "asr", "turn", "quality", "dyad", "llm"]


class FeatureFrame(BaseModel):
    """One time-stamped bundle of derived features from a single source.

    The client (Flutter web vision worker / audio worker) sends these; the raw
    video and audio never leave the WebRTC call.
    """

    t_ms: int = Field(..., description="Session-relative timestamp in milliseconds")
    source: Source
    features: dict[str, float | int | bool | str] = Field(default_factory=dict)
    quality: dict[str, float | int | bool] = Field(default_factory=dict)
    speaker_state: SpeakerState = "any"


class FrameBatch(BaseModel):
    frames: list[FeatureFrame]


class TranscriptSegment(BaseModel):
    t_start_ms: int
    t_end_ms: int
    speaker: Literal["patient", "clinician", "unknown"]
    text: str
    confidence: float = 1.0
    is_question: bool = False
    language: str = "fa"
    features: dict[str, float | int | bool | str] = Field(default_factory=dict, description="Language features already computed (e.g. lexicon ratios)")


class QuestionEvent(BaseModel):
    t_ms: int
    question_id: str
    text: str
    topic: Optional[str] = None


class ControlMessage(BaseModel):
    t_ms: int
    action: Literal["analysis_pause", "analysis_resume", "consent_withdraw", "camera_moved", "clinician_mark", "topic_segment"]
    payload: dict[str, Any] = Field(default_factory=dict)


class StartSession(BaseModel):
    session_id: str
    patient_ref: str = Field(..., description="Opaque pseudonymous id; never PII")
    clinician_ref: str
    language: str = "fa"
    baseline_window_s: Optional[float] = None
    enabled_signals: Optional[list[str]] = None
    disabled_groups: list[str] = Field(default_factory=list)
    webhook_url: Optional[str] = None
    network_rtt_ms: float = 0.0


class BehaviorEvent(BaseModel):
    id: str
    session_id: str
    signal_id: str
    group: str
    tier: str
    t_start_ms: int
    t_end_ms: int
    observation: dict[str, str]
    baseline_value: Optional[float] = None
    observed_value: Optional[float] = None
    delta: Optional[float] = None
    delta_ratio: Optional[float] = None
    z_score: Optional[float] = None
    unit: Optional[str] = None
    confidence: float
    quality: dict[str, Any] = Field(default_factory=dict)
    context: dict[str, Any] = Field(default_factory=dict)
    possible_contexts: list[dict[str, str]] = Field(default_factory=list)
    clinical_rationale: dict[str, str] = Field(default_factory=dict)
    clinical_note: dict[str, str] = Field(default_factory=dict)
    clinician_prompt: dict[str, str] = Field(default_factory=dict)
    member_events: list[str] = Field(default_factory=list)
    diagnostic_claim: None = None
    clinician_status: str = "unreviewed"


class SessionReport(BaseModel):
    session_id: str
    duration_ms: int
    conversation: dict[str, Any]
    baseline: dict[str, Any]
    behavioral_observations: dict[str, Any]
    speech: dict[str, Any]
    quality: dict[str, Any]
    events_by_tier: dict[str, int]
    strongest_changes: list[dict[str, Any]]
    clusters: list[dict[str, Any]]
    safety_flags: list[dict[str, Any]]
    ai_draft_summary: dict[str, Any]
    disclaimer: dict[str, str]
    diagnostic_claim: None = None
