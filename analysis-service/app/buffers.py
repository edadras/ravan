"""Time-windowed feature buffers with the statistics the catalog references."""

from __future__ import annotations

from collections import deque
from dataclasses import dataclass, field

import numpy as np


@dataclass
class Sample:
    t: float
    v: float
    state: str


@dataclass
class FeatureBuffer:
    max_age_s: float = 900.0
    samples: deque = field(default_factory=deque)
    events: deque = field(default_factory=deque)  # (t, state)

    def add(self, t: float, v: float, state: str) -> None:
        self.samples.append(Sample(t, v, state))
        self._trim(t)

    def add_event(self, t: float, state: str) -> None:
        self.events.append((t, state))
        self._trim(t)

    def _trim(self, now: float) -> None:
        cutoff = now - self.max_age_s
        while self.samples and self.samples[0].t < cutoff:
            self.samples.popleft()
        while self.events and self.events[0][0] < cutoff:
            self.events.popleft()

    # ------------------------------------------------------------------ queries
    def window(self, now: float, window_s: float, state: str = "any") -> list[Sample]:
        cutoff = now - window_s
        return [s for s in self.samples if s.t >= cutoff and (state == "any" or s.state == state)]

    def values(self, now: float, window_s: float, state: str = "any") -> np.ndarray:
        return np.asarray([s.v for s in self.window(now, window_s, state)], dtype=float)

    def event_count(self, now: float, window_s: float, state: str = "any") -> int:
        cutoff = now - window_s
        return sum(1 for t, st in self.events if t >= cutoff and (state == "any" or st == state))

    def stat(self, now: float, window_s: float, statistic: str, state: str = "any") -> float | None:
        arr = self.values(now, window_s, state)
        if arr.size == 0:
            return None
        if statistic == "mean":
            return float(arr.mean())
        if statistic == "median":
            return float(np.median(arr))
        if statistic == "std":
            return float(arr.std()) if arr.size > 1 else 0.0
        if statistic == "min":
            return float(arr.min())
        if statistic == "max":
            return float(arr.max())
        if statistic == "p10":
            return float(np.percentile(arr, 10))
        if statistic == "p90":
            return float(np.percentile(arr, 90))
        if statistic == "range":
            return float(arr.max() - arr.min())
        if statistic == "count":
            return float(arr.size)
        if statistic == "slope":
            ts = np.asarray([s.t for s in self.window(now, window_s, state)])
            if ts.size < 3 or np.ptp(ts) == 0:
                return 0.0
            return float(np.polyfit(ts, arr, 1)[0])
        if statistic == "autocorr_lag1":
            if arr.size < 3:
                return 0.0
            a = arr - arr.mean()
            denom = float((a * a).sum())
            return float((a[:-1] * a[1:]).sum() / denom) if denom else 0.0
        raise ValueError(f"unsupported statistic {statistic}")

    def sustained_duration(self, now: float, predicate, max_gap_s: float = 0.5) -> float:
        """Seconds for which ``predicate(value)`` has been continuously true up to ``now``."""
        dur = 0.0
        last_t = None
        for s in reversed(self.samples):
            if not predicate(s.v):
                break
            if last_t is not None and last_t - s.t > max_gap_s:
                break
            last_t = s.t
            dur = now - s.t
        return dur

    def dominant_frequency(self, now: float, window_s: float, fs: float = 25.0) -> tuple[float, float]:
        """(dominant_hz, band_power_fraction) of the last ``window_s`` seconds, resampled at ``fs``."""
        w = self.window(now, window_s)
        if len(w) < 8:
            return 0.0, 0.0
        ts = np.asarray([s.t for s in w])
        vs = np.asarray([s.v for s in w])
        if np.ptp(ts) < 1.0:
            return 0.0, 0.0
        grid = np.arange(ts[0], ts[-1], 1.0 / fs)
        if grid.size < 8:
            return 0.0, 0.0
        interp = np.interp(grid, ts, vs)
        interp = interp - interp.mean()
        spec = np.abs(np.fft.rfft(interp * np.hanning(interp.size))) ** 2
        freqs = np.fft.rfftfreq(interp.size, d=1.0 / fs)
        spec[0] = 0.0
        total = float(spec.sum())
        if total <= 0:
            return 0.0, 0.0
        k = int(np.argmax(spec))
        return float(freqs[k]), float(spec[k] / total)
