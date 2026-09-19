/**
 * Signal-conditioning primitives shared by the on-device feature extractor.
 *
 * Everything here is pure: no DOM, no MediaPipe, no timers. The accuracy
 * harness in tools/accuracy/ runs these against ground truth under Node, so
 * anything that changes a number must stay in this file (or its siblings)
 * rather than in the browser glue.
 */

const TAU = Math.PI * 2;

/**
 * One-Euro filter (Casiez, Roussel & Vogel, CHI 2012).
 *
 * Landmark streams need smoothing that does not add lag to real movement: a
 * fixed low-pass either leaves visible jitter while the subject is still or
 * smears the onset of a gesture. One-Euro raises its cutoff with the observed
 * speed, so a motionless posture is smoothed hard and a fast hand is barely
 * touched.
 *
 *   minCutoff  Hz  lower = steadier when still, more lag when moving
 *   beta           higher = follows fast movement more eagerly
 *   dCutoff    Hz  cutoff of the derivative estimate itself
 */
export class OneEuro {
  constructor({ minCutoff = 1.0, beta = 0.007, dCutoff = 1.0 } = {}) {
    this.minCutoff = minCutoff;
    this.beta = beta;
    this.dCutoff = dCutoff;
    this.xHat = null;
    this.dxHat = 0;
  }

  static alpha(cutoff, dt) {
    const tau = 1 / (TAU * cutoff);
    return 1 / (1 + tau / dt);
  }

  /** @param {number} x raw sample @param {number} dt seconds since the previous sample */
  filter(x, dt) {
    if (!Number.isFinite(x)) return this.xHat;
    if (this.xHat === null || !(dt > 0)) {
      this.xHat = x;
      this.dxHat = 0;
      return x;
    }
    const dx = (x - this.xHat) / dt;
    const ad = OneEuro.alpha(this.dCutoff, dt);
    this.dxHat = ad * dx + (1 - ad) * this.dxHat;
    const cutoff = this.minCutoff + this.beta * Math.abs(this.dxHat);
    const a = OneEuro.alpha(cutoff, dt);
    this.xHat = a * x + (1 - a) * this.xHat;
    return this.xHat;
  }

  /** Speed estimate in units/second, already smoothed. */
  get velocity() {
    return this.dxHat;
  }

  reset() {
    this.xHat = null;
    this.dxHat = 0;
  }
}

/** A One-Euro filter per landmark coordinate, keyed by landmark index. */
export class LandmarkFilter {
  constructor(opts = {}) {
    this.opts = opts;
    this.axes = new Map();
  }

  /**
   * @param {Array<{x:number,y:number,z?:number,visibility?:number}>} landmarks
   * @param {number} dt seconds since the previous frame
   * @returns {Array} a new array of smoothed landmarks (inputs are not mutated)
   */
  apply(landmarks, dt) {
    const out = new Array(landmarks.length);
    for (let i = 0; i < landmarks.length; i++) {
      const p = landmarks[i];
      let f = this.axes.get(i);
      if (!f) {
        f = [new OneEuro(this.opts), new OneEuro(this.opts), new OneEuro(this.opts)];
        this.axes.set(i, f);
      }
      out[i] = {
        x: f[0].filter(p.x, dt),
        y: f[1].filter(p.y, dt),
        z: f[2].filter(p.z ?? 0, dt),
        visibility: p.visibility,
        presence: p.presence,
      };
    }
    return out;
  }

  reset() {
    this.axes.clear();
  }
}

/**
 * Fixed-capacity ring of timestamped samples.
 *
 * Unlike an array with shift(), inserting is O(1), which matters because these
 * run per landmark at 15-30 Hz for a whole session.
 */
export class TimedRing {
  constructor(capacity) {
    this.capacity = capacity;
    this.t = new Float64Array(capacity);
    this.v = new Float64Array(capacity);
    this.head = 0;
    this.size = 0;
  }

  push(t, v) {
    this.t[this.head] = t;
    this.v[this.head] = v;
    this.head = (this.head + 1) % this.capacity;
    if (this.size < this.capacity) this.size++;
  }

  get full() {
    return this.size === this.capacity;
  }

  /** Oldest-to-newest copy. */
  toArrays() {
    const n = this.size;
    const t = new Float64Array(n);
    const v = new Float64Array(n);
    const start = (this.head - n + this.capacity) % this.capacity;
    for (let i = 0; i < n; i++) {
      const j = (start + i) % this.capacity;
      t[i] = this.t[j];
      v[i] = this.v[j];
    }
    return { t, v };
  }

  get last() {
    return this.size ? this.v[(this.head - 1 + this.capacity) % this.capacity] : undefined;
  }

  get span() {
    if (this.size < 2) return 0;
    const start = (this.head - this.size + this.capacity) % this.capacity;
    return this.t[(this.head - 1 + this.capacity) % this.capacity] - this.t[start];
  }

  mean() {
    if (!this.size) return 0;
    let s = 0;
    const start = (this.head - this.size + this.capacity) % this.capacity;
    for (let i = 0; i < this.size; i++) s += this.v[(start + i) % this.capacity];
    return s / this.size;
  }

  /** Robust centre: median. Used where a blink or a dropout must not move the estimate. */
  median() {
    if (!this.size) return 0;
    const { v } = this.toArrays();
    const a = Array.from(v).sort((p, q) => p - q);
    const m = a.length >> 1;
    return a.length % 2 ? a[m] : (a[m - 1] + a[m]) / 2;
  }

  clear() {
    this.head = 0;
    this.size = 0;
  }
}

/**
 * Dominant oscillation frequency of an irregularly sampled signal.
 *
 * Camera frames do not arrive on a uniform grid, so the samples are first
 * resampled onto one by linear interpolation, then linearly detrended (a slow
 * postural drift is not a tremor) and Hann-windowed (a rectangular window
 * leaks a strong low-frequency component across the whole band and invents
 * peaks). The search is restricted to `fMin..fMax` with the Goertzel
 * algorithm, which costs O(n) per candidate frequency instead of a full DFT.
 *
 * Postural drift and camera noise are not white: a body sway is close to a
 * random walk, whose spectrum falls as 1/f², so *some* bin always wins and a
 * plain "biggest bin" test calls a slow drift a tremor. Three guards prevent
 * that:
 *
 *  - the scan runs over a band wider than the one asked for, and a peak that
 *    lands in the margin is energy from outside the band, so nothing is
 *    reported;
 *  - the peak must carry `minProminence` of the scanned power and stand
 *    `minPeakToMedian` times above the median bin;
 *  - `fMax` is capped at 0.45 of the effective sampling rate. A 9 Hz tremor
 *    simply is not observable in a 15 fps stream, and reporting an aliased
 *    figure would be worse than reporting nothing.
 */
export function dominantFrequency(times, values, {
  fMin = 0.5, fMax = 12, resolution = 0.1, minProminence = 0.22,
  minPeakToMedian = 8, minSeconds = 2.0, guardBins = 3,
} = {}) {
  const n = times.length;
  if (n < 16) return null;
  const duration = times[n - 1] - times[0];
  if (!(duration >= minSeconds)) return null;

  // ---- respect Nyquist: nothing above 0.45 of the real sample rate is real
  const sampleRate = (n - 1) / duration;
  const fTop = Math.min(fMax, 0.45 * sampleRate);
  if (!(fTop > fMin + resolution)) return null;

  // ---- resample onto a uniform grid at twice the highest frequency of interest
  const fs = Math.max(2.5 * fTop, sampleRate);
  const m = Math.max(16, Math.floor(duration * fs));
  const grid = new Float64Array(m);
  let j = 0;
  for (let i = 0; i < m; i++) {
    const t = times[0] + (i * duration) / (m - 1);
    while (j < n - 2 && times[j + 1] < t) j++;
    const t0 = times[j], t1 = times[j + 1];
    const w = t1 > t0 ? (t - t0) / (t1 - t0) : 0;
    grid[i] = values[j] + (values[j + 1] - values[j]) * w;
  }

  // ---- linear detrend
  let sx = 0, sy = 0, sxx = 0, sxy = 0;
  for (let i = 0; i < m; i++) { sx += i; sy += grid[i]; sxx += i * i; sxy += i * grid[i]; }
  const denom = m * sxx - sx * sx;
  const slope = denom !== 0 ? (m * sxy - sx * sy) / denom : 0;
  const intercept = (sy - slope * sx) / m;
  let energy = 0;
  for (let i = 0; i < m; i++) {
    grid[i] -= intercept + slope * i;
    energy += grid[i] * grid[i];
  }
  if (energy <= 1e-12) return null;

  // ---- Hann window
  for (let i = 0; i < m; i++) grid[i] *= 0.5 * (1 - Math.cos((TAU * i) / (m - 1)));

  // ---- Goertzel across the band
  const fsGrid = (m - 1) / duration;
  const freqs = [];
  const powers = [];
  // Scan wider than the reported band; the margin is where out-of-band energy
  // piles up, and a winner in there means there is nothing to report.
  const scanLo = Math.max(resolution, fMin - guardBins * resolution);
  const scanHi = Math.min(0.49 * fsGrid, fTop + guardBins * resolution);
  let total = 0, bestIndex = 0, bestPower = -1;
  for (let f = scanLo; f <= scanHi + 1e-9; f += resolution) {
    const k = (TAU * f) / fsGrid;
    const coeff = 2 * Math.cos(k);
    let s0 = 0, s1 = 0, s2 = 0;
    for (let i = 0; i < m; i++) {
      s0 = grid[i] + coeff * s1 - s2;
      s2 = s1;
      s1 = s0;
    }
    const power = Math.max(0, s1 * s1 + s2 * s2 - coeff * s1 * s2);
    freqs.push(f);
    powers.push(power);
    total += power;
    if (power > bestPower) { bestPower = power; bestIndex = freqs.length - 1; }
  }
  if (total <= 0 || powers.length < 5) return null;

  const peak = freqs[bestIndex];
  if (peak < fMin - 1e-9 || peak > fTop + 1e-9) return null;

  const sorted = [...powers].sort((a, b) => a - b);
  const median = sorted[sorted.length >> 1];
  const peakToMedian = median > 0 ? bestPower / median : Infinity;
  const prominence = bestPower / total;
  if (prominence < minProminence || peakToMedian < minPeakToMedian) return null;

  return { hz: peak, prominence, peakToMedian };
}

/** Peak-to-peak amplitude of the buffered samples. */
export function amplitude(values) {
  let lo = Infinity, hi = -Infinity;
  for (const v of values) { if (v < lo) lo = v; if (v > hi) hi = v; }
  return hi > lo ? hi - lo : 0;
}

export const clamp = (v, lo, hi) => (v < lo ? lo : v > hi ? hi : v);
export const deg = (r) => (r * 180) / Math.PI;
