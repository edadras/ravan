"""Optional language-model layer for the end-of-session *draft* summary.

Design rules:
* The model only ever sees structured, de-identified event data and transcript
  excerpts that the clinician already has.
* The system prompt forbids diagnosis, emotion certainty and deception claims.
* Output passes through ``guard()`` which removes any forbidden label before it
  reaches the clinician, and the clinician must Accept / Edit / Reject.
* Provider is chosen by ``RAVAN_LLM_PROVIDER`` (none | anthropic | openai);
  the default is ``none`` which produces a deterministic template summary.
"""

from __future__ import annotations

import json
import os
import re
from typing import Protocol

import httpx

FORBIDDEN_PATTERNS = [
    r"\b(is|are|seems?|appears?)\s+(clearly\s+)?(anxious|depressed|lying|deceptive|psychotic|manic|borderline|narcissistic)\b",
    r"\b(diagnos(is|e|ed)|disorder|suicidal risk|risk of suicide)\b",
    r"\b(lie|lying|deception|deceptive|dishonest)\b",
    r"\b(genuine|fake)\s+smile\b",
    r"(تشخیص|اختلال|دروغ|فریب|افسرده است|مضطرب است|خطر خودکشی)",
    r"(tanı|bozukluk|yalan|aldat|depresif|kaygılı|intihar riski)",
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


class Provider(Protocol):
    def complete(self, system: str, user: str, language: str) -> str: ...


class NullProvider:
    """Deterministic template used when no LLM is configured (and in tests)."""

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


class AnthropicProvider:
    def __init__(self) -> None:
        self.key = os.environ["ANTHROPIC_API_KEY"]
        self.model = os.environ.get("RAVAN_LLM_MODEL", "")
        if not self.model:
            raise RuntimeError("RAVAN_LLM_MODEL must be set (see docs/07-ai-provider.md)")

    def complete(self, system: str, user: str, language: str) -> str:
        r = httpx.post("https://api.anthropic.com/v1/messages",
                       headers={"x-api-key": self.key, "anthropic-version": "2023-06-01", "content-type": "application/json"},
                       json={"model": self.model, "max_tokens": 1200, "system": system,
                             "messages": [{"role": "user", "content": f"Language: {language}\n{user}"}]}, timeout=60)
        r.raise_for_status()
        return "".join(b.get("text", "") for b in r.json()["content"])


class OpenAIProvider:
    def __init__(self) -> None:
        self.key = os.environ["OPENAI_API_KEY"]
        self.model = os.environ.get("RAVAN_LLM_MODEL", "")
        if not self.model:
            raise RuntimeError("RAVAN_LLM_MODEL must be set")

    def complete(self, system: str, user: str, language: str) -> str:
        r = httpx.post("https://api.openai.com/v1/chat/completions",
                       headers={"Authorization": f"Bearer {self.key}"},
                       json={"model": self.model, "messages": [{"role": "system", "content": system},
                                                               {"role": "user", "content": f"Language: {language}\n{user}"}]}, timeout=60)
        r.raise_for_status()
        return r.json()["choices"][0]["message"]["content"]


def get_provider() -> Provider:
    name = os.environ.get("RAVAN_LLM_PROVIDER", "none").lower()
    if name == "anthropic":
        return AnthropicProvider()
    if name == "openai":
        return OpenAIProvider()
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
