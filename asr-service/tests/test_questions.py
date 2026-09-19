"""Question detection, against a labelled set in all three languages.

A clinician's question anchors every response-latency signal, so a statement
mistaken for a question re-anchors the measurement: the delay the clinician
reads is then the delay since the wrong sentence. Precision matters more here
than recall, which is why these assertions are mostly about what must *not* be
called a question.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app.questions import is_question  # noqa: E402

QUESTIONS = [
    # Persian
    ("رابطه شما با خانواده چطور است؟", "fa"),
    ("چرا این موضوع الان برایتان مهم شده", "fa"),
    ("آیا شب‌ها راحت می‌خوابید", "fa"),
    ("چند وقت است این حال را دارید", "fa"),
    ("کجا بیشتر این احساس را تجربه می‌کنید", "fa"),
    # English
    ("How have you been sleeping?", "en"),
    ("Why do you think that happened", "en"),
    ("Did you talk to her about it", "en"),
    ("Are you able to work at the moment", "en"),
    ("What does that feel like", "en"),
    # Turkish
    ("Bu hafta nasıl geçti?", "tr"),
    ("Neden böyle hissettiğinizi düşünüyorsunuz", "tr"),
    ("Onunla konuştunuz mu", "tr"),
    ("Ne zaman başladı", "tr"),
]

STATEMENTS = [
    # Persian: the copulas and verbs the old word list treated as interrogatives.
    ("حالش خوب بود", "fa"),
    ("امروز اینجا هستم", "fa"),
    ("شما یک هفته وقت دارید", "fa"),
    ("کاری که کردی درست بود", "fa"),          # `که` relativises `کردی`
    ("هر چه گفتید را یادداشت کردم", "fa"),    # `چه` inside a relative clause
    ("می‌شه گفت بهتر شده", "fa"),
    # English: wh-words and auxiliaries mid-sentence.
    ("I know what you mean", "en"),
    ("Tell me when you are ready", "en"),
    ("That is where it started", "en"),
    ("You can take your time", "en"),
    ("I was thinking about that too", "en"),
    ("We have talked about this before", "en"),
    # Turkish
    ("Bunu daha önce konuşmuştuk", "tr"),
    ("Ne olursa olsun yanınızdayım", "tr"),
    ("Bugün biraz yorgun görünüyorsunuz", "tr"),
]


def score(predict):
    tp = sum(1 for t, l in QUESTIONS if predict(t, l))
    fp = sum(1 for t, l in STATEMENTS if predict(t, l))
    recall = tp / len(QUESTIONS)
    precision = tp / (tp + fp) if tp + fp else 0.0
    return precision, recall, fp


def test_questions_are_recognised_in_all_three_languages():
    for text, lang in QUESTIONS:
        assert is_question(text, lang), f"missed a question: {text!r} ({lang})"


def test_ordinary_statements_are_not_questions():
    wrong = [(t, l) for t, l in STATEMENTS if is_question(t, l)]
    assert not wrong, f"statements marked as questions: {wrong}"


def test_it_beats_the_heuristic_it_replaced():
    """The previous rule: any interrogative anywhere in a short sentence."""
    legacy_words = {
        "fa": ["چطور", "چطوره", "چگونه", "چرا", "چی", "چه", "کی", "کجا", "آیا", "چند",
               "کدام", "کدوم", "می‌تونی", "میتونی", "می‌شه", "میشه", "داری", "دارید", "هست", "بود"],
        "en": ["how", "why", "what", "when", "where", "who", "which", "do you", "did you",
               "are you", "have you", "can you", "could you", "would you", "is it", "was it"],
        "tr": ["nasıl", "neden", "niçin", "ne", "ne zaman", "nerede", "kim", "hangi",
               "mi", "mı", "mu", "mü", "misin", "mısın", "musun", "müsün"],
    }

    def legacy(text, lang):
        import re
        t = text.strip()
        if re.search(r"[?؟]\s*$", t):
            return True
        low = t.lower()
        words = legacy_words.get(lang, legacy_words["en"])
        first = " ".join(low.split()[:3])
        return any(first.startswith(w) or f" {w} " in f" {low} " for w in words) and len(low.split()) <= 12

    new_precision, new_recall, new_fp = score(is_question)
    old_precision, old_recall, old_fp = score(legacy)

    assert new_precision > old_precision, (
        f"precision did not improve: {new_precision:.2f} vs {old_precision:.2f}"
    )
    assert new_fp < old_fp, f"false positives: {new_fp} vs {old_fp}"
    assert new_recall >= old_recall, f"recall regressed: {new_recall:.2f} vs {old_recall:.2f}"


def test_a_rising_tail_only_promotes_a_short_utterance():
    """Prosody alone is enough for "you slept badly?" and nothing longer."""
    assert is_question("خوب خوابیدید", "fa", rising=True)
    assert not is_question("خوب خوابیدید", "fa", rising=False)
    long_statement = "I was thinking we could go back to what you mentioned last week about your brother"
    assert not is_question(long_statement, "en", rising=True)
