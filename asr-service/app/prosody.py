"""Did the speaker's pitch rise at the end of the utterance?

A declarative question carries no interrogative word — "you slept badly?" is a
question only because of its intonation. The service used to accept a
`rising_intonation` form field for exactly this, but nothing ever sent it: the
browser did not measure it and the backend did not forward it, so the parameter
was always False and declarative questions were never recognised.

Rather than plumb a measurement through three services, it is taken here, from
the audio this service already has. The pitch track comes from normalised
autocorrelation over the utterance's final stretch; the decision is the slope of
that track in semitones per second, which is scale-free and so comparable
between a low and a high voice.

If ffmpeg is unavailable or the audio cannot be decoded, every utterance simply
reports no rise, and question detection falls back to its lexical rules.
"""

from __future__ import annotations

import array
import logging
import math
import shutil
import subprocess

log = logging.getLogger("ravan.prosody")

SAMPLE_RATE = 16000
FRAME_S = 0.040
HOP_S = 0.020
# Covers a low male voice through a high female one; anything outside is noise
# or a harmonic error rather than a speaker's fundamental.
F0_MIN_HZ = 70
F0_MAX_HZ = 400
# The final stretch of the utterance, where a question's rise happens.
TAIL_S = 0.45
# A terminal rise in a question is large; ordinary declination is negative and
# continuation rises are gentler. Measured in semitones per second.
RISE_SEMITONES_PER_S = 4.0


def decode_pcm(audio: bytes) -> array.array | None:
    """Decode arbitrary container bytes to 16 kHz mono signed 16-bit PCM."""
    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        return None
    try:
        out = subprocess.run(
            [ffmpeg, "-loglevel", "quiet", "-i", "pipe:0", "-f", "s16le",
             "-ac", "1", "-ar", str(SAMPLE_RATE), "pipe:1"],
            input=audio, capture_output=True, timeout=30, check=True,
        ).stdout
    except (subprocess.SubprocessError, OSError):
        log.debug("prosody: could not decode audio", exc_info=True)
        return None
    if len(out) < 2:
        return None
    pcm = array.array("h")
    pcm.frombytes(out[: len(out) - (len(out) % 2)])
    return pcm


def _f0(frame: list[float]) -> float | None:
    """Fundamental frequency by normalised autocorrelation, or None if unvoiced."""
    n = len(frame)
    mean = sum(frame) / n
    x = [v - mean for v in frame]
    energy = sum(v * v for v in x)
    if energy < 1e-6:
        return None

    lo = int(SAMPLE_RATE / F0_MAX_HZ)
    hi = min(int(SAMPLE_RATE / F0_MIN_HZ), n - 1)
    best_lag, best_score = 0, 0.0
    for lag in range(lo, hi):
        num = 0.0
        for i in range(n - lag):
            num += x[i] * x[i + lag]
        denom = math.sqrt(energy * sum(v * v for v in x[lag:]) + 1e-12)
        score = num / denom if denom > 0 else 0.0
        if score > best_score:
            best_score, best_lag = score, lag
    # Below this the peak is not a periodic voice, it is noise correlating with
    # itself, and its "pitch" would be arbitrary.
    if best_lag == 0 or best_score < 0.45:
        return None
    return SAMPLE_RATE / best_lag


def tail_slope_semitones_per_s(pcm: array.array, start_s: float, end_s: float) -> float | None:
    """Slope of the pitch track over the final TAIL_S of [start_s, end_s]."""
    if not pcm:
        return None
    tail_start = max(start_s, end_s - TAIL_S)
    a = int(tail_start * SAMPLE_RATE)
    b = min(int(end_s * SAMPLE_RATE), len(pcm))
    if b - a < int(FRAME_S * SAMPLE_RATE) * 3:
        return None

    frame_n = int(FRAME_S * SAMPLE_RATE)
    hop_n = int(HOP_S * SAMPLE_RATE)
    times, semitones = [], []
    for start in range(a, b - frame_n, hop_n):
        f0 = _f0([float(v) for v in pcm[start:start + frame_n]])
        if f0 is None:
            continue
        times.append((start + frame_n / 2) / SAMPLE_RATE)
        # Semitones relative to a fixed reference: the difference between two
        # values is then a musical interval, independent of the speaker's range.
        semitones.append(12 * math.log2(f0 / 100.0))

    # A slope needs enough voiced frames to mean anything.
    if len(times) < 5:
        return None
    n = len(times)
    mt = sum(times) / n
    ms = sum(semitones) / n
    num = sum((times[i] - mt) * (semitones[i] - ms) for i in range(n))
    den = sum((times[i] - mt) ** 2 for i in range(n))
    if den <= 0:
        return None
    return num / den


def rising(pcm: array.array | None, start_s: float, end_s: float) -> tuple[bool, float | None]:
    """(did the pitch rise, the measured slope) for one utterance."""
    if pcm is None:
        return False, None
    slope = tail_slope_semitones_per_s(pcm, start_s, end_s)
    if slope is None:
        return False, None
    return slope >= RISE_SEMITONES_PER_S, round(slope, 2)
