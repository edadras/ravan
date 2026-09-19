"""Is this utterance a question?

Why it matters: a question from the clinician is the anchor for every
response-latency signal. "The answer came 4.7 s after the question" is measured
from the end of the last utterance marked as a question, so a statement
mistaken for one silently re-anchors the measurement and the number shown to
the clinician is the delay since the wrong sentence.

The previous heuristic marked an utterance as a question when any interrogative
word appeared *anywhere* in a sentence of twelve words or fewer. In Persian the
word list included the copulas ``هست`` and ``بود``, so "حالش خوب بود" — "they
were doing fine" — was a question. In English "I know what you mean" was a
question. In Turkish "ne" matched inside a wider phrase.

What is here instead: punctuation first, then per-language structure, with
interrogatives required in the positions where they actually mark a question,
and the copulas removed. Falling back on prosody alone is allowed only for a
short utterance whose pitch genuinely rises (see prosody.py); a rise is
otherwise treated as supporting evidence, not proof.
"""

from __future__ import annotations

import re

# Sentence-initial wh-words. English questions are wh-fronted or
# subject-auxiliary inverted, so position carries almost all the signal.
EN_WH = {"how", "why", "what", "when", "where", "who", "whom", "whose", "which"}
EN_AUX = {"do", "does", "did", "are", "is", "was", "were", "have", "has", "had",
          "can", "could", "would", "will", "shall", "should", "may", "might", "am"}
EN_SUBJECTS = {"you", "he", "she", "it", "they", "we", "i", "there", "that", "this"}

# Persian interrogatives. Word order is freer than English, so these count
# anywhere in the utterance — but `هست`, `بود`, `داری` and `دارید` are ordinary
# copulas and verbs, and including them is what made nearly every short
# statement a question.
FA_WH = {"چرا", "چطور", "چطوره", "چگونه", "چه", "چی", "چند", "چقدر", "کجا", "کجاست",
         "کی", "کدام", "کدوم", "چیه", "چیست", "چندتا"}
FA_POLAR = {"آیا"}          # explicit yes/no marker, always sentence-initial
# `که` turns most of the words above into relative pronouns: "کاری که کردی"
# ("the thing that you did") is not a question.
FA_RELATIVISER = "که"
# `هر` before an interrogative makes a free relative: "هر چه گفتید" is
# "whatever you said", not "what did you say".
FA_UNIVERSAL = "هر"
FA_UNIVERSAL_JOINED = ("هرچه", "هرچی", "هرکس", "هرکسی", "هرکجا", "هروقت", "هرکدام", "هرقدر", "هرچقدر")

TR_WH = {"nasıl", "neden", "niçin", "ne", "niye", "kim", "kime", "kimin", "hangi",
         "nerede", "nereye", "nereden", "kaç", "kaçta", "ne zaman"}
# The Turkish question particle is a separate word and always utterance-final.
TR_PARTICLE = re.compile(r"\b(mi|mı|mu|mü|misin|mısın|musun|müsün|miyim|mıyım|"
                         r"muyum|müyüm|miydi|mıydı|muydu|müydü|mi̇)\b\s*[.!]?\s*$")
# The conditional suffix -sa/-se, which turns a wh-word into a free relative.
TR_CONDITIONAL = re.compile(r"(sa|se|sun|sın|sin|sün)$")

EXPLICIT_MARK = re.compile(r"[?？؟]\s*$")
PUNCT = re.compile(r"[.,!;:،؛۔]+")

# A declarative question ("you're feeling better?") is short. Beyond this, a
# rising tail is far more likely to be a continuation than a question.
PROSODY_MAX_WORDS = 8


def _words(text: str) -> list[str]:
    return [w for w in PUNCT.sub(" ", text.lower()).split() if w]


def _english(words: list[str]) -> bool:
    if not words:
        return False
    head = words[0]
    if head in EN_WH:
        return True
    # Subject-auxiliary inversion: "did you sleep", "are you able".
    if head in EN_AUX and len(words) > 1 and words[1] in EN_SUBJECTS:
        return True
    # "how long have you", "what kind of" — the wh-word still opens the clause.
    if len(words) > 1 and words[1] in EN_WH and head in {"and", "so", "but", "okay", "ok"}:
        return True
    return False


def _persian(words: list[str]) -> bool:
    if not words:
        return False
    if words[0] in FA_POLAR:
        return True
    if any(w in FA_UNIVERSAL_JOINED for w in words):
        return False
    for i, w in enumerate(words):
        if w not in FA_WH:
            continue
        previous = words[i - 1] if i > 0 else ""
        # A wh-word after `که` is relativised and after `هر` is universal;
        # neither asks anything.
        if previous in (FA_RELATIVISER, FA_UNIVERSAL):
            continue
        return True
    return False


def _turkish(text: str, words: list[str]) -> bool:
    if TR_PARTICLE.search(text.lower()):
        return True
    if not words:
        return False
    for i, w in enumerate(words[:2]):
        if w not in TR_WH:
            continue
        # A wh-word followed by a conditional is a free relative, not a
        # question: "ne olursa olsun" is "whatever happens".
        if any(TR_CONDITIONAL.search(nxt) for nxt in words[i + 1:i + 3]):
            continue
        return True
    return False


def is_question(text: str, lang: str, rising: bool = False) -> bool:
    """`rising` comes from prosody.py: the pitch of the utterance's tail rose."""
    stripped = text.strip()
    if not stripped:
        return False
    if EXPLICIT_MARK.search(stripped):
        return True

    words = _words(stripped)
    if not words:
        return False

    structural = {
        "en": lambda: _english(words),
        "fa": lambda: _persian(words),
        "tr": lambda: _turkish(stripped, words),
    }.get(lang, lambda: _english(words))()

    if structural:
        return True
    # No lexical or syntactic cue at all: only a genuine rise on a short
    # utterance is enough, and only when prosody was actually measured.
    return rising and len(words) <= PROSODY_MAX_WORDS
