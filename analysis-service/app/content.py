"""Transcript-derived content flags.

Only explicit verbal content is used here.  Nothing in this module reads
face, body or voice features.  The safety lexicon is deliberately conservative
(explicit phrases only) and every hit links to the exact transcript segment so
the clinician reads the words, not a score.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

# Explicit phrases only.  Extend per deployment language with clinical review.
SAFETY_LEXICON = {
    "fa": [
        r"خودکشی", r"خودم را بکشم", r"خودمو بکشم", r"می‌?خوا(هم|م) بمیرم", r"به زندگی(م)? پایان", r"خود ?آزاری",
        r"به خودم آسیب", r"دیگه نمی‌?خوا(هم|م) زنده", r"کاش نبودم", r"می‌?خوا(هم|م) (او|اون|کسی) را بکشم", r"کتک(م)? می‌?زن",
        r"تهدید(م)? (می‌?کنه|کرد)", r"آزار جنسی", r"تجاوز",
    ],
    "en": [
        r"\bkill myself\b", r"\bsuicid", r"\bend my life\b", r"\bwant to die\b", r"\bhurt myself\b", r"\bself[- ]harm\b",
        r"\bwish i (was|were) dead\b", r"\bkill (him|her|them|someone)\b", r"\bhits? me\b", r"\bthreaten(s|ed)? me\b", r"\babus(e|ed|ing)\b",
    ],
}

NEGATIVE_LEXICON = {
    "fa": ["غمگین", "ناراحت", "بد", "خسته", "تنها", "ترس", "نگران", "عصبی", "بی‌فایده", "افتضاح", "وحشتناک", "گریه", "درد"],
    "en": ["sad", "upset", "bad", "tired", "alone", "afraid", "worried", "nervous", "useless", "awful", "terrible", "cry", "pain"],
}
POSITIVE_LEXICON = {
    "fa": ["خوب", "خوشحال", "آرام", "امید", "عالی", "راحت", "لذت", "دوست دارم", "خوشبخت"],
    "en": ["good", "happy", "calm", "hope", "great", "relaxed", "enjoy", "love", "fine"],
}
ABSOLUTIST = {"fa": ["همیشه", "هرگز", "هیچ‌وقت", "هیچوقت", "کاملاً", "کاملا", "همه", "هیچ‌کس", "هیچکس", "اصلاً", "اصلا"],
              "en": ["always", "never", "completely", "totally", "everyone", "nobody", "nothing", "entirely"]}
HEDGES = {"fa": ["شاید", "فکر کنم", "احتمالاً", "احتمالا", "به نظرم", "نمی‌دونم", "نمیدونم", "یه جورایی"],
          "en": ["maybe", "i guess", "probably", "i think", "sort of", "kind of", "i don't know"]}
FIRST_PERSON = {"fa": ["من", "منو", "مرا", "خودم", "برام", "بهم"], "en": ["i", "me", "my", "myself", "mine"]}
MINIMAL = {"fa": ["نه", "آره", "بله", "نمی‌دونم", "نمیدونم", "هیچی", "خوبم", "عادی"], "en": ["no", "yes", "yeah", "nothing", "fine", "ok", "okay", "dunno"]}


@dataclass
class ContentFeatures:
    word_count: int
    negative_ratio: float
    positive_ratio: float
    absolutist_ratio: float
    hedge_ratio: float
    first_person_ratio: float
    minimal_response: bool
    safety_hits: list[str]


def _tokens(text: str) -> list[str]:
    return [t for t in re.split(r"[\s،,.!?؟:;()\[\]\"'«»]+", text.lower()) if t]


def analyze(text: str, language: str = "fa", is_answer_to_open_question: bool = False) -> ContentFeatures:
    lang = language if language in SAFETY_LEXICON else "en"
    toks = _tokens(text)
    n = max(len(toks), 1)
    joined = " ".join(toks)

    def ratio(lex: list[str]) -> float:
        return sum(1 for t in toks if t in lex) / n

    def phrase_ratio(lex: list[str]) -> float:
        return sum(joined.count(p) for p in lex) / n

    hits = [p for p in SAFETY_LEXICON[lang] if re.search(p, text.lower())]
    minimal = is_answer_to_open_question and len(toks) <= 2 and (not toks or toks[0] in MINIMAL[lang] or len(toks) <= 2)
    return ContentFeatures(
        word_count=len(toks),
        negative_ratio=ratio(NEGATIVE_LEXICON[lang]),
        positive_ratio=ratio(POSITIVE_LEXICON[lang]),
        absolutist_ratio=phrase_ratio(ABSOLUTIST[lang]),
        hedge_ratio=phrase_ratio(HEDGES[lang]),
        first_person_ratio=ratio(FIRST_PERSON[lang]),
        minimal_response=minimal,
        safety_hits=hits,
    )
