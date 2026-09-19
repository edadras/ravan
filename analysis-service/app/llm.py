"""Language-model layer: end-of-session draft summary and the clinical assistant.

Design rules:
* The model only ever sees structured, de-identified data (pseudonym, ages, transcript
  text, behavioural events, clinician notes, screening scores) that the clinician already has.
* System prompts forbid diagnosis-as-fact, emotion certainty, deception claims and risk scores.
* Every output passes through ``guard()`` / ``guard_json()`` before it reaches the clinician,
  and the clinician must Accept / Edit / Reject in the backend.
* Provider is chosen by ``RAVAN_LLM_PROVIDER`` (none | openai | anthropic). ``none`` returns
  deterministic template output so the whole platform works, and is testable, without a key.
"""

from __future__ import annotations

import json
import os
import re
from typing import Any, Protocol

import httpx

FORBIDDEN_PATTERNS = [
    r"\b(is|are|seems?|appears?)\s+(clearly\s+)?(anxious|depressed|lying|deceptive|psychotic|manic|borderline|narcissistic)\b",
    r"\b(definite|definitive|confirmed)\s+diagnosis\b",
    r"\b(lie|lying|deception|deceptive|dishonest)\b",
    r"\b(genuine|fake)\s+smile\b",
    r"\b(suicide|suicidal)\s+risk\s+(score|level|is (high|low|moderate))\b",
    r"(دروغ|فریب|افسرده است|مضطرب است|قطعاً افسرده|قطعاً مضطرب|تشخیص قطعی|امتیاز خطر خودکشی)",
    r"(yalan|aldat|kesin tanı|intihar risk puanı|(kesinlikle|açıkça)\s+(depresif|kaygılı|manik|psikotik)|(depresif|kaygılı)\s+görünüyor)",
]

SYSTEM_PROMPT = """You draft a neutral end-of-session observation summary for a licensed mental-health clinician.
You receive only structured behavioural observations (what changed, by how much, versus the patient's own
session baseline, at what data quality) and short transcript excerpts.
Rules you must follow:
1. Describe observations; never diagnose, never name a disorder, never state an emotion as fact.
2. Never claim deception, lying, hidden feelings, genuine vs fake expressions, or risk scores.
3. Always attribute changes to the patient's own baseline in this session, never to population norms.
4. List benign or technical explanations before any clinical one.
5. Explicit safety-relevant statements are quoted verbatim with timestamps, without interpretation.
6. Write in the requested language (fa = Persian, en = English, tr = Turkish), concise, with timestamps, as a draft the clinician will edit.
7. End with the sentence 'Draft for clinician review. Not a clinical assessment.' translated into the requested language."""

ASSISTANT_SYSTEM_PROMPT = """You are a clinical decision-support assistant for a licensed psychologist or psychiatrist.
You receive a de-identified clinical record (history, prior diagnoses, screening scores), the transcript of a
tele-therapy session, clinician notes and behavioural observations (numeric changes versus the patient's own
baseline in this session). Your job is to help the clinician think, never to decide for them.

Hard rules:
1. You produce HYPOTHESES for the clinician to consider, with explicit evidence for AND against each one, and
   what information would confirm or rule it out. You never state a diagnosis as established.
2. Behavioural observations (posture, gaze, voice) are weak, non-specific signals. Cite them only as
   "co-occurring changes", never as proof of an emotion, of deception, of hidden thoughts or of a diagnosis.
3. Never claim to know what the patient thinks or feels. Describe what was said and how it was said
   (latency, length, topic shifts, hedging, valence of words), then list possible interpretations.
4. Quote safety-relevant statements verbatim with timestamps; do not compute or imply a risk score.
   Recommend the clinician follow their safety protocol.
5. Use ICD-11 chapter 06 codes when naming a hypothesis. Use standard screening instruments
   (PHQ-9, GAD-7, PCL-5, ISI, AUDIT-C, etc.) when suggesting next steps.
6. Be concise, structured, and write all free text in the requested language (fa / en / tr).
7. Output ONLY valid JSON matching the requested schema. No markdown."""

FORMULATION_SCHEMA = {
    "summary": "string – 3-6 sentences, observations only",
    "key_observations": [{"t": "mm:ss", "observation": "string", "evidence": "string", "source": "transcript|behaviour|record|screening"}],
    "qa_highlights": [{"t": "mm:ss", "question": "string", "how_answered": "string", "possible_interpretations": ["string"]}],
    "differential": [{
        "label": "string", "icd11": "string or null",
        "evidence_for": ["string"], "evidence_against": ["string"],
        "confidence": "low|moderate|high", "would_confirm": ["string"], "would_rule_out": ["string"],
        "suggested_questions": ["string"], "suggested_instruments": ["string"],
    }],
    "risk_relevant_statements": [{"t": "mm:ss", "quote": "string", "note": "clinician to follow safety protocol"}],
    "suggested_next_steps": ["string"],
    "limitations": ["string"],
    "disclaimer": "string",
}

QA_SCHEMA = {
    "pairs": [{
        "t": "mm:ss", "question": "string", "answer": "string",
        "descriptors": ["string – from: brief, elaborated, delayed, immediate, hedged, absolutist, negative-valence, positive-valence, topic-shift, on-topic, minimal, self-corrected, interrupted"],
        "co_occurring_changes": ["string – behavioural signal ids that changed near this answer"],
        "how_answered": "string – one neutral sentence",
        "possible_interpretations": ["string – benign first"],
        "follow_up_question": "string",
    }],
    "patterns": ["string – across the session, e.g. 'answers about family were shorter and later than baseline'"],
    "disclaimer": "string",
}

CHAT_SCHEMA = {"answer": "string with timestamps", "citations": [{"t": "mm:ss", "kind": "transcript|behaviour|record", "text": "string"}], "disclaimer": "string"}

DISCLAIMER = {
    "en": "Decision support only. Hypotheses require clinician judgement; behavioural signals are non-specific and compared only with this patient's own session baseline.",
    "fa": "فقط پشتیبان تصمیم. فرضیه‌ها نیازمند قضاوت درمانگرند؛ سیگنال‌های رفتاری غیراختصاصی‌اند و فقط با خط پایه همین بیمار در همین جلسه مقایسه شده‌اند.",
    "tr": "Yalnızca karar desteği. Hipotezler klinisyen yargısı gerektirir; davranışsal sinyaller özgül değildir ve yalnızca bu hastanın kendi seans taban çizgisiyle karşılaştırılmıştır.",
}


class Provider(Protocol):
    name: str
    model: str

    def complete(self, system: str, user: str, language: str) -> str: ...

    def complete_json(self, system: str, user: str, schema: dict, language: str) -> dict: ...


class NullProvider:
    """Deterministic templates used when no LLM is configured (and in tests)."""

    name = "NullProvider"
    model = "template"

    def complete(self, system: str, user: str, language: str) -> str:
        data = json.loads(user)
        lines = []
        if language == "fa":
            lines.append(f"مدت جلسه: {data['duration_min']} دقیقه. سهم گفتار بیمار: {data['patient_speech_share']}٪.")
            lines.append(f"{data['n_change_events']} تغییر نسبت به خط پایه و {data['n_clusters']} خوشه چندوجهی ثبت شد.")
            for c in data["strongest"][:5]:
                lines.append(f"- {c['t']}: {c['observation_fa']} (مقدار {c['observed']} در برابر خط پایه {c['baseline']}; کیفیت {c['quality']}). زمینه‌های محتمل: {c['contexts_fa']}.")
            for s in data["safety"]:
                lines.append(f"- {s['t']}: عبارت صریح در متن: «{s['text']}» — برای بازبینی درمانگر.")
            lines.append("پیش‌نویس برای بازبینی درمانگر. ارزیابی بالینی نیست.")
        elif language == "tr":
            lines.append(f"Seans süresi: {data['duration_min']} dk. Hastanın konuşma payı: %{data['patient_speech_share']}.")
            lines.append(f"Taban çizgisine göre {data['n_change_events']} değişim ve {data['n_clusters']} çok kanallı küme kaydedildi.")
            for c in data["strongest"][:5]:
                lines.append(f"- {c['t']}: {c['observation_tr']} (gözlenen {c['observed']}, taban çizgisi {c['baseline']}; kalite {c['quality']}). Olası bağlamlar: {c['contexts_tr']}.")
            for s in data["safety"]:
                lines.append(f"- {s['t']}: transkriptte açık ifade: \"{s['text']}\" — klinisyen incelemesi için.")
            lines.append("Klinisyen incelemesi için taslak. Klinik bir değerlendirme değildir.")
        else:
            lines.append(f"Session length {data['duration_min']} min. Patient speech share {data['patient_speech_share']}%.")
            lines.append(f"{data['n_change_events']} changes from baseline and {data['n_clusters']} multimodal clusters were recorded.")
            for c in data["strongest"][:5]:
                lines.append(f"- {c['t']}: {c['observation_en']} (observed {c['observed']} vs baseline {c['baseline']}; quality {c['quality']}). Possible contexts: {c['contexts_en']}.")
            for s in data["safety"]:
                lines.append(f"- {s['t']}: explicit statement in transcript: \"{s['text']}\" — for clinician review.")
            lines.append("Draft for clinician review. Not a clinical assessment.")
        return "\n".join(lines)

    def complete_json(self, system: str, user: str, schema: dict, language: str) -> dict:
        data = json.loads(user)
        d = DISCLAIMER.get(language, DISCLAIMER["en"])
        if "pairs" in schema:  # QA analysis: the deterministic features are already computed; pass through
            return {"pairs": data.get("pairs", []), "patterns": data.get("patterns", []), "disclaimer": d}
        if "answer" in schema:  # chat
            return {"answer": {"fa": "بدون مدل زبانی پیکربندی‌شده، فقط داده ساخت‌یافته در دسترس است. رویدادها و متن را در کنسول ببینید.",
                               "tr": "Yapılandırılmış bir dil modeli yok; yalnızca yapılandırılmış veriler kullanılabilir. Olayları ve transkripti konsolda görün.",
                               "en": "No language model configured; only structured data is available. See events and transcript in the console."}[language if language in ("fa", "tr") else "en"],
                    "citations": [], "disclaimer": d}
        screenings = data.get("record", {}).get("screenings") or []
        hyp = []
        for s in screenings[:3]:
            hyp.append({"label": f"Screening {s.get('instrument', '').upper()} band: {s.get('band')}", "icd11": None,
                        "evidence_for": [f"{s.get('instrument')} total {s.get('total')}"], "evidence_against": [],
                        "confidence": "low", "would_confirm": ["clinical interview"], "would_rule_out": ["clinical interview"],
                        "suggested_questions": [], "suggested_instruments": []})
        return {"summary": {"fa": "بدون مدل زبانی؛ خلاصه ساخت‌یافته از رویدادها و پرسشنامه‌ها.", "tr": "Dil modeli yok; olaylar ve taramalardan yapılandırılmış özet.", "en": "No language model; structured summary from events and screenings."}[language if language in ("fa", "tr") else "en"],
                "key_observations": [{"t": e.get("t", ""), "observation": e.get("observation", {}).get(language, e.get("observation", {}).get("en", "")), "evidence": f"z={e.get('z_score')}", "source": "behaviour"} for e in data.get("top_events", [])[:8]],
                "qa_highlights": [], "differential": hyp, "risk_relevant_statements": data.get("safety_quotes", []),
                "suggested_next_steps": [], "limitations": ["no language model configured"], "disclaimer": d}


class OpenAIProvider:
    name = "OpenAIProvider"

    def __init__(self) -> None:
        self.key = os.environ["OPENAI_API_KEY"]
        self.model = os.environ.get("RAVAN_LLM_MODEL", "")
        self.base = os.environ.get("OPENAI_BASE_URL", "https://api.openai.com/v1")
        if not self.model:
            raise RuntimeError("RAVAN_LLM_MODEL must be set (see docs/07-ai-provider.md)")

    def _chat(self, messages: list[dict], json_mode: bool) -> str:
        body: dict[str, Any] = {"model": self.model, "messages": messages, "temperature": 0.2}
        if json_mode:
            body["response_format"] = {"type": "json_object"}
        r = httpx.post(f"{self.base}/chat/completions", headers={"Authorization": f"Bearer {self.key}"}, json=body, timeout=120)
        r.raise_for_status()
        return r.json()["choices"][0]["message"]["content"]

    def complete(self, system: str, user: str, language: str) -> str:
        return self._chat([{"role": "system", "content": system}, {"role": "user", "content": f"Language: {language}\n{user}"}], False)

    def complete_json(self, system: str, user: str, schema: dict, language: str) -> dict:
        prompt = f"Language: {language}\nReturn JSON with exactly this shape:\n{json.dumps(schema, ensure_ascii=False)}\n\nDATA:\n{user}"
        return _parse_json(self._chat([{"role": "system", "content": system}, {"role": "user", "content": prompt}], True))


class AnthropicProvider:
    name = "AnthropicProvider"

    def __init__(self) -> None:
        self.key = os.environ["ANTHROPIC_API_KEY"]
        self.model = os.environ.get("RAVAN_LLM_MODEL", "")
        if not self.model:
            raise RuntimeError("RAVAN_LLM_MODEL must be set (see docs/07-ai-provider.md)")

    def _msg(self, system: str, user: str) -> str:
        r = httpx.post("https://api.anthropic.com/v1/messages",
                       headers={"x-api-key": self.key, "anthropic-version": "2023-06-01", "content-type": "application/json"},
                       json={"model": self.model, "max_tokens": 4000, "system": system, "messages": [{"role": "user", "content": user}]}, timeout=120)
        r.raise_for_status()
        return "".join(b.get("text", "") for b in r.json()["content"])

    def complete(self, system: str, user: str, language: str) -> str:
        return self._msg(system, f"Language: {language}\n{user}")

    def complete_json(self, system: str, user: str, schema: dict, language: str) -> dict:
        prompt = f"Language: {language}\nReturn ONLY JSON with exactly this shape:\n{json.dumps(schema, ensure_ascii=False)}\n\nDATA:\n{user}"
        return _parse_json(self._msg(system, prompt))


def _parse_json(text: str) -> dict:
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```(?:json)?\s*|\s*```$", "", text)
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        m = re.search(r"\{.*\}", text, re.S)
        if not m:
            raise
        return json.loads(m.group(0))


def get_provider() -> Provider:
    name = os.environ.get("RAVAN_LLM_PROVIDER", "none").lower()
    if name == "openai":
        return OpenAIProvider()
    if name == "anthropic":
        return AnthropicProvider()
    return NullProvider()


def guard(text: str) -> tuple[str, list[str]]:
    """Strip forbidden claims; return (clean_text, removed_matches)."""
    removed: list[str] = []
    out_lines = []
    for line in text.splitlines():
        hit = None
        for pat in FORBIDDEN_PATTERNS:
            m = re.search(pat, line, flags=re.IGNORECASE)
            if m:
                hit = m.group(0)
                break
        if hit:
            removed.append(hit)
            out_lines.append("[sentence removed by guardrail: contained a non-permitted inference]")
        else:
            out_lines.append(line)
    return "\n".join(out_lines), removed


def guard_json(obj: Any, removed: list[str] | None = None) -> tuple[Any, list[str]]:
    """Apply guard() to every string inside a JSON structure."""
    removed = [] if removed is None else removed
    if isinstance(obj, str):
        clean, r = guard(obj)
        removed.extend(r)
        return clean, removed
    if isinstance(obj, list):
        return [guard_json(x, removed)[0] for x in obj], removed
    if isinstance(obj, dict):
        return {k: guard_json(v, removed)[0] for k, v in obj.items()}, removed
    return obj, removed
