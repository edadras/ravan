"""Generic detectors that execute catalog detector specs.

Each detector returns a ``Candidate`` (or ``None``).  The engine turns candidates
into events after applying cooldowns and attaching context.  Detectors never
produce labels; they only compute *what changed, by how much, versus which
baseline, at what quality*.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import Optional

import numpy as np

from .baseline import Baseline
from .buffers import FeatureBuffer


@dataclass
class Candidate:
    signal_id: str
    t_start: float
    t_end: float
    observed: Optional[float]
    baseline: Optional[float]
    delta: Optional[float]
    delta_ratio: Optional[float]
    z: Optional[float]
    confidence: float
    feature: str
    unit: Optional[str] = None
    extra: dict = field(default_factory=dict)


def _confidence(strength: float, threshold: float, quality: float) -> float:
    """Map exceedance-over-threshold and gate quality into [0.3, 0.98]."""
    if threshold <= 0:
        excess = strength
    else:
        excess = max(0.0, (abs(strength) - abs(threshold)) / abs(threshold))
    base = 0.55 + 0.35 * (1 - math.exp(-excess))
    return round(max(0.3, min(0.98, base * max(0.5, min(1.0, quality)))), 3)


class DetectorContext:
    """What a detector is allowed to see for one signal evaluation."""

    def __init__(self, now: float, buffers: dict[str, FeatureBuffer], baseline: Baseline, quality_score: float, units: dict[str, str]):
        self.now = now
        self.buffers = buffers
        self.baseline = baseline
        self.quality_score = quality_score
        self.units = units

    def buf(self, feature: str) -> FeatureBuffer | None:
        return self.buffers.get(feature)


# ----------------------------------------------------------------------------- individual detectors

def level_change(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    if not ctx.baseline.ready:
        return None
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None:
        return None
    state = det.get("speaker_state", "any")
    statistic = det.get("statistic", "mean")
    value = buf.stat(ctx.now, det["window_s"], statistic, state)
    if value is None:
        return None
    fb = ctx.baseline.get(feature, state)
    if fb is None:
        return None
    # baseline of the statistic: median for mean/median, sigma for std-like statistics
    base_val = fb.sigma if statistic in ("std", "range") else fb.median
    sigma = fb.sigma if statistic not in ("std", "range") else max(fb.sigma * 0.5, 1e-6)
    z = (value - base_val) / sigma if sigma > 0 else 0.0
    thr = det.get("threshold", 2.0)
    direction = det.get("direction")
    if direction == "up" and z < abs(thr):
        return None
    if direction == "down" and z > -abs(thr):
        return None
    if direction is None and abs(z) < abs(thr):
        return None
    ratio = (value / base_val) if abs(base_val) > 1e-9 else None
    return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, value, base_val, value - base_val, ratio, z,
                     _confidence(z, thr, ctx.quality_score), feature, ctx.units.get(feature), {"statistic": statistic})


def rate_change(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    if not ctx.baseline.ready:
        return None
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None:
        return None
    state = det.get("speaker_state", "any")
    count = buf.event_count(ctx.now, det["window_s"], state)
    rate = count / (det["window_s"] / 60.0)
    fb = ctx.baseline.get(feature, state)
    base_rate = fb.rate_per_min if fb and fb.rate_per_min is not None else 0.0
    thr = det.get("threshold", 1.8)
    direction = det.get("direction", "up" if thr >= 1 else "down")
    if base_rate <= 0:
        # no baseline events: require an absolute minimum to call an increase; never call a decrease
        if direction == "down" or count < max(3, det.get("min_count", 3)):
            return None
        ratio = math.inf
    else:
        ratio = rate / base_rate
        if direction == "up" and ratio < thr:
            return None
        if direction == "down" and ratio > thr:
            return None
        if direction == "up" and count < 2:
            return None
    strength = (ratio if ratio != math.inf else thr * 2) if direction == "up" else (1 / max(ratio, 1e-6))
    return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, rate, base_rate, rate - base_rate,
                     None if ratio == math.inf else ratio, None, _confidence(strength, thr if direction == "up" else 1 / thr, ctx.quality_score),
                     feature, "/min", {"count": count})


def sustained(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None or not buf.samples:
        return None
    thr = det.get("threshold", 0.0)
    direction = det.get("direction")
    use_z = det.get("comparison") == "z_vs_session_baseline"
    fb = ctx.baseline.get(feature, det.get("speaker_state", "any")) if use_z else None
    if use_z and fb is None:
        return None

    def transform(v: float) -> float:
        return fb.z(v) if use_z else v

    if direction == "up":
        pred = lambda v: transform(v) >= thr  # noqa: E731
    elif direction == "down":
        pred = lambda v: transform(v) <= thr  # noqa: E731
    else:
        pred = lambda v: abs(transform(v)) >= abs(thr)  # noqa: E731
    dur = buf.sustained_duration(ctx.now, pred)
    if dur < det["min_duration_s"]:
        return None
    last = buf.samples[-1].v
    observed = transform(last)
    return Candidate(signal["id"], ctx.now - dur, ctx.now, last, fb.median if fb else None,
                     (last - fb.median) if fb else None, None, observed if use_z else None,
                     _confidence(dur, det["min_duration_s"], ctx.quality_score), feature, ctx.units.get(feature),
                     {"duration_s": round(dur, 1)})


def event(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    feature = signal["features"][0] if signal["features"] else None
    if feature is None:
        return None
    buf = ctx.buf(feature)
    if buf is None:
        return None
    state = det.get("speaker_state", "any")
    unit = ctx.units.get(feature)
    if unit in ("event", "cat", "bool"):
        count = buf.event_count(ctx.now, det["window_s"], state)
        if count < det.get("threshold", 1):
            return None
        return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, float(count), None, None, None, None,
                         _confidence(count, det.get("threshold", 1), ctx.quality_score), feature, "count", {"count": count})
    # numeric features: an "event" is the window max exceeding the threshold (raw or z if baseline ready)
    arr = buf.values(ctx.now, det["window_s"], state)
    if arr.size == 0:
        return None
    fb = ctx.baseline.get(feature, state)
    peak = float(arr.max())
    strength = fb.z(peak) if fb and abs(det.get("threshold", 1)) <= 3 else peak
    if strength < det.get("threshold", 1):
        return None
    return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, peak, fb.median if fb else None,
                     (peak - fb.median) if fb else None, None, fb.z(peak) if fb else None,
                     _confidence(strength, det.get("threshold", 1), ctx.quality_score), feature, unit)


def periodicity(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    lo, hi = det.get("frequency_band_hz", [0.5, 5.0])
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None:
        return None
    if feature.endswith("_hz"):
        # the client already estimated an oscillation frequency; check it sits in band for long enough
        dur = buf.sustained_duration(ctx.now, lambda v: lo <= v <= hi)
        if dur < det["min_duration_s"]:
            return None
        return Candidate(signal["id"], ctx.now - dur, ctx.now, buf.samples[-1].v, None, None, None, None,
                         _confidence(dur, det["min_duration_s"], ctx.quality_score), feature, "Hz", {"duration_s": round(dur, 1)})
    hz, frac = buf.dominant_frequency(ctx.now, max(det["window_s"], det["min_duration_s"] * 2))
    if not (lo <= hz <= hi) or frac < det.get("threshold", 0.5):
        return None
    return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, hz, None, None, None, None,
                     _confidence(frac, det.get("threshold", 0.5), ctx.quality_score), feature, "Hz", {"band_power_fraction": round(frac, 3)})


def quality(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    if not signal["features"]:
        return None
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None or not buf.samples:
        return None
    thr = det.get("threshold", 0.0)
    direction = det.get("direction")
    if direction == "up":
        pred = lambda v: v >= thr  # noqa: E731
    elif direction == "down":
        pred = lambda v: v <= thr  # noqa: E731
    else:
        pred = lambda v: v == thr  # noqa: E731
    dur = buf.sustained_duration(ctx.now, pred)
    if dur < det["min_duration_s"] or (det["min_duration_s"] == 0 and not pred(buf.samples[-1].v)):
        return None
    return Candidate(signal["id"], ctx.now - dur, ctx.now, buf.samples[-1].v, None, None, None, None, 0.9, feature,
                     ctx.units.get(feature), {"duration_s": round(dur, 1)})


def trend(signal: dict, ctx: DetectorContext) -> Candidate | None:
    det = signal["detector"]
    if not ctx.baseline.ready:
        return None
    feature = signal["features"][0]
    buf = ctx.buf(feature)
    if buf is None:
        return None
    arr = buf.values(ctx.now, det["window_s"])
    if arr.size < 20:
        return None
    ts = np.asarray([s.t for s in buf.window(ctx.now, det["window_s"])])
    slope = float(np.polyfit(ts, arr, 1)[0]) * 60.0  # per minute
    fb = ctx.baseline.get(feature)
    if fb is None or fb.sigma <= 0:
        return None
    norm = slope / fb.sigma  # sigma units per minute
    thr = det.get("threshold", 0.3)
    direction = det.get("direction")
    if direction == "up" and norm < thr:
        return None
    if direction == "down" and norm > -thr:
        return None
    if direction is None and abs(norm) < thr:
        return None
    return Candidate(signal["id"], ctx.now - det["window_s"], ctx.now, slope, 0.0, slope, None, norm,
                     _confidence(norm, thr, ctx.quality_score), feature, f"{ctx.units.get(feature)}/min", {"sigma_per_min": round(norm, 3)})


DETECTORS = {
    "level_change": level_change,
    "rate_change": rate_change,
    "sustained": sustained,
    "event": event,
    "periodicity": periodicity,
    "quality": quality,
    "trend": trend,
}
