"""Clinical assistant: Q&A response analysis, formulation drafts and session chat.

What this module deliberately does NOT do: read the patient's mind. Given a question and an
answer, it describes *how* the answer was given (latency, length, hedging, valence, topic
overlap, co-occurring behavioural changes) and lists possible interpretations, benign first.
The language model then helps the clinician organise hypotheses; every output is a suggestion.
"""

from __future__ import annotations

import re
from typing import Any

from .content import ABSOLUTIST, HEDGES, MINIMAL, NEGATIVE_LEXICON, POSITIVE_LEXICON, _lower, _tokens
from .llm import ASSISTANT_SYSTEM_PROMPT, CHAT_SCHEMA, DISCLAIMER, FORMULATION_SCHEMA, QA_SCHEMA, get_provider, guard_json

STOP = {
    "fa": {"و", "در", "به", "از", "که", "را", "با", "این", "آن", "است", "شما", "چطور", "چه", "چی", "آیا", "می", "یک", "هم", "برای", "تا", "یا", "من", "تو"},
    "en": {"the", "a", "an", "and", "or", "of", "to", "in", "on", "is", "are", "you", "your", "how", "what", "do", "does", "did", "i", "it", "that", "this", "with", "about"},
    "tr": {"ve", "bir", "bu", "şu", "o", "ile", "için", "mi", "mı", "mu", "mü", "nasıl", "ne", "sen", "siz", "ben", "da", "de", "ki", "gibi", "çok", "daha"},
}


def _fmt_t(ms: int) -> str:
    s = int(ms) // 1000
    return f"{s // 60:02d}:{s % 60:02d}"


def _content_words(text: str, lang: str) -> set[str]:
    return {t for t in _tokens(text, lang) if len(t) > 2 and t not in STOP.get(lang, STOP["en"])}


def pair_questions(transcript: list[dict]) -> list[dict]:
    """Group each clinician question with the patient utterances that follow it."""
    pairs: list[dict] = []
    current: dict | None = None
    for seg in sorted(transcript, key=lambda s: s["t_start_ms"]):
        if seg["speaker"] == "clinician":
            if current and current["answers"]:
                pairs.append(current)
                current = None
            if seg.get("is_question") or re.search(r"[?؟]\s*$", seg["text"].strip()):
                current = {"q": seg, "answers": []}
            elif current and not current["answers"]:
                current["q"] = {**current["q"], "text": current["q"]["text"] + " " + seg["text"], "t_end_ms": seg["t_end_ms"]}
        elif seg["speaker"] == "patient" and current is not None:
            current["answers"].append(seg)
    if current and current["answers"]:
        pairs.append(current)
    return pairs


def analyze_pair(pair: dict, events: list[dict], lang: str, baseline_latency_ms: float | None, baseline_words: float | None) -> dict:
    q, answers = pair["q"], pair["answers"]
    answer_text = " ".join(a["text"] for a in answers)
    latency = answers[0]["t_start_ms"] - q["t_end_ms"]
    words = len(_tokens(answer_text, lang))
    toks = _tokens(answer_text, lang)
    joined = " ".join(toks)
    n = max(len(toks), 1)
    lex = lambda L: sum(1 for t in toks if t in L) / n  # noqa: E731
    phr = lambda L: sum(joined.count(p) for p in L) / n  # noqa: E731
    neg, pos = lex(NEGATIVE_LEXICON.get(lang, NEGATIVE_LEXICON["en"])), lex(POSITIVE_LEXICON.get(lang, POSITIVE_LEXICON["en"]))
    hedge, absol = phr(HEDGES.get(lang, HEDGES["en"])), phr(ABSOLUTIST.get(lang, ABSOLUTIST["en"]))
    minimal = words <= 2 or (words <= 4 and toks and toks[0] in MINIMAL.get(lang, MINIMAL["en"]))
    qw, aw = _content_words(q["text"], lang), _content_words(answer_text, lang)
    overlap = len(qw & aw) / len(qw) if qw else 1.0
    descriptors: list[str] = []
    if minimal:
        descriptors.append("minimal")
    elif baseline_words and words < 0.5 * baseline_words:
        descriptors.append("brief")
    elif baseline_words and words > 2.0 * baseline_words:
        descriptors.append("elaborated")
    if baseline_latency_ms is not None and latency > max(2.0 * baseline_latency_ms, baseline_latency_ms + 1500):
        descriptors.append("delayed")
    elif latency < 300:
        descriptors.append("immediate")
    if hedge > 0.08:
        descriptors.append("hedged")
    if absol > 0.05:
        descriptors.append("absolutist")
    if neg > 0.08:
        descriptors.append("negative-valence")
    if pos > 0.08:
        descriptors.append("positive-valence")
    if not minimal and words >= 6 and overlap == 0.0:
        descriptors.append("topic-shift")
    elif overlap > 0:
        descriptors.append("on-topic")
    if len(answers) > 1 and any(a["t_start_ms"] - answers[i]["t_end_ms"] > 2500 for i, a in enumerate(answers[1:])):
        descriptors.append("self-corrected")
    win_start, win_end = q["t_end_ms"] - 2000, answers[-1]["t_end_ms"] + 10000
    co = sorted({e["signal_id"] for e in events if e.get("tier") not in ("quality",) and win_start <= e["t_start_ms"] <= win_end})
    interpretations = _interpretations(descriptors, lang)
    how = _how_answered(descriptors, latency, words, co, lang)
    return {
        "t": _fmt_t(q["t_end_ms"]), "t_ms": q["t_end_ms"], "question": q["text"], "answer": answer_text[:600],
        "latency_ms": latency, "words": words, "hedge_ratio": round(hedge, 3), "absolutist_ratio": round(absol, 3),
        "negative_ratio": round(neg, 3), "positive_ratio": round(pos, 3), "question_overlap": round(overlap, 2),
        "descriptors": descriptors, "co_occurring_changes": co, "how_answered": how, "possible_interpretations": interpretations,
        "follow_up_question": "",
    }


def _how_answered(desc: list[str], latency: int, words: int, co: list[str], lang: str) -> str:
    d = ", ".join(desc) or "unremarkable"
    if lang == "fa":
        return f"پاسخ پس از {latency / 1000:.1f} ثانیه، با {words} کلمه؛ ویژگی‌ها: {d}؛ {len(co)} تغییر رفتاری هم‌زمان."
    if lang == "tr":
        return f"Yanıt {latency / 1000:.1f} sn sonra, {words} sözcükle; özellikler: {d}; eşzamanlı {len(co)} davranış değişimi."
    return f"Answered after {latency / 1000:.1f} s with {words} words; features: {d}; {len(co)} co-occurring behavioural changes."


def _interpretations(desc: list[str], lang: str) -> list[str]:
    table = {
        "delayed": {"en": ["thinking / recalling", "network delay", "difficulty with the topic", "question not understood"], "fa": ["فکر کردن / یادآوری", "تأخیر شبکه", "دشواری موضوع", "سؤال فهمیده نشده"], "tr": ["düşünme / hatırlama", "ağ gecikmesi", "konunun zorluğu", "soru anlaşılmadı"]},
        "minimal": {"en": ["closed-question style", "fatigue", "reluctance to elaborate", "topic discomfort"], "fa": ["سبک سؤال بسته", "خستگی", "بی‌میلی به توضیح", "ناراحتی با موضوع"], "tr": ["kapalı soru tarzı", "yorgunluk", "açıklamaya isteksizlik", "konuyla ilgili rahatsızlık"]},
        "brief": {"en": ["question type", "fatigue", "less to say", "reluctance"], "fa": ["نوع سؤال", "خستگی", "حرف کمتر", "بی‌میلی"], "tr": ["soru türü", "yorgunluk", "söylenecek az şey", "isteksizlik"]},
        "elaborated": {"en": ["engagement with topic", "need to explain", "circumstantiality"], "fa": ["درگیری با موضوع", "نیاز به توضیح", "حاشیه‌روی"], "tr": ["konuya katılım", "açıklama ihtiyacı", "dolambaçlılık"]},
        "hedged": {"en": ["uncertainty", "politeness", "communication style"], "fa": ["عدم قطعیت", "ادب", "سبک ارتباطی"], "tr": ["belirsizlik", "nezaket", "iletişim tarzı"]},
        "absolutist": {"en": ["emphasis", "figure of speech", "all-or-nothing framing"], "fa": ["تأکید", "اصطلاح", "چارچوب همه‌یا‌هیچ"], "tr": ["vurgu", "deyim", "ya hep ya hiç çerçevesi"]},
        "negative-valence": {"en": ["topic content", "current distress described verbally"], "fa": ["محتوای موضوع", "پریشانی فعلی که کلامی بیان شده"], "tr": ["konu içeriği", "sözel olarak ifade edilen sıkıntı"]},
        "topic-shift": {"en": ["question misheard", "association", "avoidance of topic (only if pattern repeats)"], "fa": ["سؤال درست شنیده نشده", "تداعی", "اجتناب از موضوع (فقط اگر الگو تکرار شود)"], "tr": ["soru yanlış duyuldu", "çağrışım", "konudan kaçınma (yalnızca örüntü tekrarlarsa)"]},
        "immediate": {"en": ["familiar topic", "prepared answer", "eagerness"], "fa": ["موضوع آشنا", "پاسخ آماده", "اشتیاق"], "tr": ["tanıdık konu", "hazır yanıt", "heveslilik"]},
    }
    out: list[str] = []
    for d in desc:
        for s in table.get(d, {}).get(lang, table.get(d, {}).get("en", [])):
            if s not in out:
                out.append(s)
    return out


def qa_analysis(transcript: list[dict], events: list[dict], language: str) -> dict:
    pairs = pair_questions(transcript)
    lat = [p["answers"][0]["t_start_ms"] - p["q"]["t_end_ms"] for p in pairs]
    wc = [len(_tokens(" ".join(a["text"] for a in p["answers"]), language)) for p in pairs]
    base_lat = sorted(lat)[len(lat) // 2] if lat else None
    base_wc = sum(wc) / len(wc) if wc else None
    analysed = [analyze_pair(p, events, language, base_lat, base_wc) for p in pairs]
    patterns = _patterns(analysed, language)
    data = {"pairs": analysed, "patterns": patterns, "baseline_latency_ms": base_lat, "baseline_words": base_wc}
    provider = get_provider()
    out = provider.complete_json(ASSISTANT_SYSTEM_PROMPT, _dump(data), QA_SCHEMA, language)
    out.setdefault("pairs", analysed)
    out.setdefault("disclaimer", DISCLAIMER.get(language, DISCLAIMER["en"]))
    # always keep the deterministic numbers next to the model's wording
    by_t = {p["t"]: p for p in analysed}
    for p in out.get("pairs", []):
        det = by_t.get(p.get("t"))
        if det:
            p.setdefault("latency_ms", det["latency_ms"])
            p.setdefault("words", det["words"])
            p.setdefault("descriptors", det["descriptors"])
            p.setdefault("co_occurring_changes", det["co_occurring_changes"])
    clean, removed = guard_json(out)
    return {"output": clean, "guardrail_removed": removed, "provider": provider.name, "model": provider.model, "deterministic": data}


def _patterns(pairs: list[dict], lang: str) -> list[str]:
    if len(pairs) < 3:
        return []
    out = []
    delayed = [p["t"] for p in pairs if "delayed" in p["descriptors"]]
    minimal = [p["t"] for p in pairs if "minimal" in p["descriptors"] or "brief" in p["descriptors"]]
    shifts = [p["t"] for p in pairs if "topic-shift" in p["descriptors"]]
    if len(delayed) >= 2:
        out.append({"fa": f"پاسخ‌های با تأخیر بیش از خط پایه در: {', '.join(delayed)}", "tr": f"Taban çizgisinden geç yanıtlar: {', '.join(delayed)}", "en": f"Answers later than baseline at: {', '.join(delayed)}"}[lang if lang in ("fa", "tr") else "en"])
    if len(minimal) >= 2:
        out.append({"fa": f"پاسخ‌های کوتاه/حداقلی در: {', '.join(minimal)}", "tr": f"Kısa/asgari yanıtlar: {', '.join(minimal)}", "en": f"Brief/minimal answers at: {', '.join(minimal)}"}[lang if lang in ("fa", "tr") else "en"])
    if len(shifts) >= 2:
        out.append({"fa": f"تغییر موضوع مکرر در: {', '.join(shifts)} — الگو، نه نتیجه", "tr": f"Tekrarlayan konu değişimi: {', '.join(shifts)} — örüntü, sonuç değil", "en": f"Repeated topic shifts at: {', '.join(shifts)} — a pattern, not a conclusion"}[lang if lang in ("fa", "tr") else "en"])
    return out


def formulation(payload: dict) -> dict:
    language = payload.get("language", "en")
    transcript = payload.get("transcript", [])
    events = payload.get("events", [])
    qa = qa_analysis(transcript, events, language)["deterministic"]
    top = sorted([e for e in events if e.get("tier") in ("change", "cluster", "medical")], key=lambda e: abs(e.get("z_score") or 0) * (e.get("confidence") or 0), reverse=True)[:12]
    safety = [{"t": _fmt_t(e["t_start_ms"]), "quote": (e.get("context") or {}).get("transcript_text", ""), "note": "clinician to follow safety protocol"} for e in events if e.get("tier") == "safety"]
    data = {
        "language": language,
        "patient": payload.get("patient", {}),
        "record": payload.get("record", {}),
        "transcript": [{"t": _fmt_t(s["t_start_ms"]), "speaker": s["speaker"], "text": s["text"][:400]} for s in transcript][:400],
        "top_events": [{"t": _fmt_t(e["t_start_ms"]), "signal_id": e["signal_id"], "observation": e.get("observation", {}), "z_score": e.get("z_score"), "confidence": e.get("confidence"), "context": {k: v for k, v in (e.get("context") or {}).items() if k in ("preceding_question_text", "topic_segment", "member_signals")}} for e in top],
        "qa": qa["pairs"], "qa_patterns": qa["patterns"],
        "clinician_notes": payload.get("clinician_notes", []),
        "report_summary": (payload.get("report") or {}).get("behavioral_observations"),
        "safety_quotes": safety,
    }
    provider = get_provider()
    out = provider.complete_json(ASSISTANT_SYSTEM_PROMPT, _dump(data), FORMULATION_SCHEMA, language)
    out.setdefault("disclaimer", DISCLAIMER.get(language, DISCLAIMER["en"]))
    out["risk_relevant_statements"] = safety or out.get("risk_relevant_statements", [])
    for h in out.get("differential", []):
        h.setdefault("confidence", "low")
        h["status"] = "hypothesis_for_clinician_review"
    clean, removed = guard_json(out)
    return {"output": clean, "guardrail_removed": removed, "provider": provider.name, "model": provider.model}


def chat(payload: dict) -> dict:
    language = payload.get("language", "en")
    transcript = payload.get("transcript", [])
    events = payload.get("events", [])
    data = {
        "language": language, "question": payload.get("question", ""), "history": payload.get("history", [])[-10:],
        "record": payload.get("record", {}),
        "transcript": [{"t": _fmt_t(s["t_start_ms"]), "speaker": s["speaker"], "text": s["text"][:300]} for s in transcript][:500],
        "events": [{"t": _fmt_t(e["t_start_ms"]), "signal_id": e["signal_id"], "tier": e.get("tier"), "z_score": e.get("z_score"), "context": {k: v for k, v in (e.get("context") or {}).items() if k in ("preceding_question_text", "topic_segment")}} for e in events if e.get("tier") != "quality"][:300],
        "clinician_notes": payload.get("clinician_notes", []),
    }
    provider = get_provider()
    out = provider.complete_json(ASSISTANT_SYSTEM_PROMPT + "\nAnswer the clinician's question using only the DATA; cite timestamps.", _dump(data), CHAT_SCHEMA, language)
    out.setdefault("disclaimer", DISCLAIMER.get(language, DISCLAIMER["en"]))
    clean, removed = guard_json(out)
    return {"output": clean, "guardrail_removed": removed, "provider": provider.name, "model": provider.model}


def _dump(data: Any) -> str:
    import json

    return json.dumps(data, ensure_ascii=False, default=str)


__all__ = ["qa_analysis", "formulation", "chat", "pair_questions", "analyze_pair", "_lower"]
