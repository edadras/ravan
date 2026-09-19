"""Clinical assistant: Q&A pairing/descriptors, formulation structure, guardrails; no network."""

import os
import sys

import pytest
from fastapi.testclient import TestClient

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from app import assist  # noqa: E402
from app.llm import guard_json  # noqa: E402
from app.main import app  # noqa: E402

TRANSCRIPT = [
    {"t_start_ms": 0, "t_end_ms": 2000, "speaker": "clinician", "text": "حالت چطوره؟", "is_question": True},
    {"t_start_ms": 2300, "t_end_ms": 6000, "speaker": "patient", "text": "خوبم، این هفته کار زیاد داشتم ولی خوب بود"},
    {"t_start_ms": 7000, "t_end_ms": 9000, "speaker": "clinician", "text": "رابطه شما با خانواده چطور است؟", "is_question": True},
    {"t_start_ms": 14500, "t_end_ms": 15000, "speaker": "patient", "text": "هیچی"},
    {"t_start_ms": 16000, "t_end_ms": 18000, "speaker": "clinician", "text": "خوابت چطوره؟", "is_question": True},
    {"t_start_ms": 18400, "t_end_ms": 24000, "speaker": "patient", "text": "شاید یه جورایی بد، همیشه نصف شب بیدار می‌شم و خسته‌ام"},
    {"t_start_ms": 25000, "t_end_ms": 27000, "speaker": "clinician", "text": "کارت چطور پیش می‌ره؟", "is_question": True},
    {"t_start_ms": 28000, "t_end_ms": 34000, "speaker": "patient", "text": "دیروز فیلم قشنگی دیدم درباره کوه‌نوردی و منظره‌های زیبا"},
]
EVENTS = [
    {"signal_id": "response_latency_increase", "group": "speech_prosody", "tier": "change", "t_start_ms": 14500, "t_end_ms": 15000, "z_score": 2.9, "confidence": 0.86,
     "observation": {"en": "latency up", "fa": "تأخیر", "tr": "gecikme"}, "context": {"preceding_question_text": "رابطه شما با خانواده چطور است؟"}},
    {"signal_id": "hand_to_face_event", "group": "hands_arms", "tier": "observation", "t_start_ms": 15500, "t_end_ms": 16000, "z_score": None, "confidence": 0.7, "observation": {"en": "x", "fa": "x", "tr": "x"}, "context": {}},
    {"signal_id": "low_light", "group": "environment_technical", "tier": "quality", "t_start_ms": 15600, "t_end_ms": 16000, "confidence": 0.9, "observation": {"en": "x", "fa": "x", "tr": "x"}, "context": {}},
    {"signal_id": "safety_relevant_phrase", "group": "speech_fluency_language", "tier": "safety", "t_start_ms": 40000, "t_end_ms": 42000, "confidence": 0.9, "observation": {"en": "x", "fa": "x", "tr": "x"}, "context": {"transcript_text": "دیگه نمی‌خوام زنده باشم"}},
]


def test_pairs_and_descriptors_describe_how_not_what_they_think():
    res = assist.qa_analysis(TRANSCRIPT, EVENTS, "fa")
    pairs = res["output"]["pairs"]
    assert len(pairs) == 4
    fam = next(p for p in pairs if "خانواده" in p["question"])
    assert "minimal" in fam["descriptors"] and "delayed" in fam["descriptors"]
    assert fam["co_occurring_changes"] == ["hand_to_face_event", "response_latency_increase"]  # quality-tier event excluded
    assert "ناراحتی با موضوع" in fam["possible_interpretations"] and fam["possible_interpretations"][0] != "ناراحتی با موضوع"  # benign first
    sleep = next(p for p in pairs if "خواب" in p["question"])
    assert {"hedged", "absolutist", "negative-valence"} <= set(sleep["descriptors"])
    work = next(p for p in pairs if "کار" in p["question"] and "پیش" in p["question"])
    assert "topic-shift" in work["descriptors"]
    assert res["provider"] == "NullProvider" and res["guardrail_removed"] == []
    assert res["output"]["disclaimer"]
    for p in pairs:
        assert "think" not in p["how_answered"].lower()


def test_formulation_structure_and_safety_quotes_and_guard():
    payload = {"language": "en", "patient": {"pseudonym": "abc", "age_years": 30}, "transcript": TRANSCRIPT, "events": EVENTS,
               "record": {"screenings": [{"instrument": "gad7", "total": 12, "band": "moderate"}], "diagnoses": []}, "clinician_notes": []}
    res = assist.formulation(payload)
    out = res["output"]
    assert out["risk_relevant_statements"][0]["quote"] == "دیگه نمی‌خوام زنده باشم"
    assert all(h["status"] == "hypothesis_for_clinician_review" for h in out["differential"])
    assert out["differential"][0]["confidence"] == "low"
    assert "Decision support only" in out["disclaimer"]
    clean, removed = guard_json({"summary": "The patient is clearly depressed and lying.", "differential": [{"evidence_for": ["Posture changed 2x vs baseline"]}]})
    assert removed and "removed by guardrail" in clean["summary"]
    assert clean["differential"][0]["evidence_for"][0].startswith("Posture")


def test_http_endpoints_require_token_when_configured(monkeypatch):
    client = TestClient(app)
    r = client.post("/assist/qa", json={"transcript": TRANSCRIPT, "events": EVENTS, "language": "tr"})
    assert r.status_code == 200 and r.json()["output"]["pairs"][0]["t"] == "00:02"
    monkeypatch.setattr("app.main.API_TOKEN", "secret")
    assert client.post("/assist/chat", json={"question": "x"}).status_code == 401
    r = client.post("/assist/chat", json={"question": "where did posture change?", "language": "en", "transcript": [], "events": []}, headers={"Authorization": "Bearer secret"})
    assert r.status_code == 200 and "disclaimer" in r.json()["output"]


@pytest.mark.parametrize("lang,word", [("fa", "پاسخ پس از"), ("tr", "Yanıt"), ("en", "Answered after")])
def test_how_answered_is_localised(lang, word):
    res = assist.qa_analysis(TRANSCRIPT, EVENTS, lang)
    assert word in res["output"]["pairs"][0]["how_answered"]
