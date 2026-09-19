"""Pitch-track measurement, against synthetic tones with a known contour.

The service previously accepted a `rising_intonation` flag that nothing ever
sent, so declarative questions — "you slept badly?", carrying no interrogative
word — were never recognised. The measurement is taken here instead.
"""

import array
import math
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app import prosody  # noqa: E402

SR = prosody.SAMPLE_RATE


def tone(duration_s: float, f_start: float, f_end: float) -> array.array:
    """A glide from f_start to f_end, with harmonics so it reads as a voice."""
    n = int(duration_s * SR)
    pcm = array.array("h")
    phase = 0.0
    for i in range(n):
        f = f_start + (f_end - f_start) * (i / max(1, n - 1))
        phase += 2 * math.pi * f / SR
        v = math.sin(phase) + 0.4 * math.sin(2 * phase) + 0.2 * math.sin(3 * phase)
        pcm.append(int(max(-1.0, min(1.0, v / 1.6)) * 12000))
    return pcm


def test_a_rising_tail_is_detected():
    pcm = tone(1.0, 120, 190)
    rises, slope = prosody.rising(pcm, 0.0, 1.0)
    assert rises, f"a clear rise was missed (slope {slope})"
    assert slope > prosody.RISE_SEMITONES_PER_S


def test_a_falling_tail_is_not_a_rise():
    pcm = tone(1.0, 190, 120)
    rises, slope = prosody.rising(pcm, 0.0, 1.0)
    assert not rises
    assert slope is not None and slope < 0


def test_a_level_tail_is_not_a_rise():
    pcm = tone(1.0, 150, 152)
    rises, _ = prosody.rising(pcm, 0.0, 1.0)
    assert not rises


def test_silence_and_missing_audio_report_nothing_rather_than_guessing():
    assert prosody.rising(None, 0.0, 1.0) == (False, None)
    assert prosody.rising(array.array("h", [0] * SR), 0.0, 1.0) == (False, None)


def test_the_slope_is_scale_free_so_high_and_low_voices_agree():
    """Same musical interval, an octave apart: the slope must match."""
    low = prosody.tail_slope_semitones_per_s(tone(1.0, 100, 150), 0.0, 1.0)
    high = prosody.tail_slope_semitones_per_s(tone(1.0, 200, 300), 0.0, 1.0)
    assert low is not None and high is not None
    assert abs(low - high) < 1.5, f"{low} vs {high}"
