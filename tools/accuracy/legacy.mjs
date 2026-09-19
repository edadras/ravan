/**
 * The feature math as it stood before this branch, extracted verbatim from the
 * original flutter_app/web/vision_worker.js.
 *
 * It exists only so the benchmarks can report *how much* the rewrite changed
 * each measurement instead of asserting that it improved. Nothing ships this.
 */

const deg = (r) => (r * 180) / Math.PI;

export function legacyHeadPose(M) {
  return {
    yaw: deg(Math.atan2(-M[8], Math.hypot(M[0], M[4]))),
    pitch: deg(Math.atan2(M[9], M[10])),
    roll: deg(Math.atan2(M[4], M[0])),
  };
}

/** Running-mean threshold, contaminated by the blink it is trying to detect. */
export class LegacyBlink {
  constructor(n = 8) {
    this.buf = [];
    this.n = n;
    this.closed = false;
    this.start = 0;
  }

  update(ear, tMs) {
    this.buf.push(ear);
    if (this.buf.length > this.n) this.buf.shift();
    const mean = this.buf.reduce((a, b) => a + b, 0) / this.buf.length;
    const thr = 0.6 * (mean || ear);
    if (ear < thr && !this.closed) {
      this.closed = true;
      this.start = tMs;
      return { blink: false, durationMs: 0 };
    }
    if (ear >= thr && this.closed) {
      this.closed = false;
      const durationMs = tMs - this.start;
      return { blink: durationMs < 500, durationMs };
    }
    return { blink: false, durationMs: 0 };
  }
}

/** Rectangular-window DFT over the raw buffer, no detrend, no resampling. */
export function legacyDominantHz(buf, fs) {
  const x = buf;
  const n = x.length;
  if (n < 16) return 0;
  const m = x.reduce((a, b) => a + b, 0) / n;
  let best = 0, bestP = 0;
  for (let k = 1; k < n / 2; k++) {
    let re = 0, im = 0;
    for (let i = 0; i < n; i++) {
      const a = (2 * Math.PI * k * i) / n;
      re += (x[i] - m) * Math.cos(a);
      im -= (x[i] - m) * Math.sin(a);
    }
    const p = re * re + im * im;
    if (p > bestP) { bestP = p; best = k; }
  }
  const total = x.reduce((s, v) => s + (v - m) * (v - m), 0) * n;
  return total > 0 && bestP / total > 0.25 ? (best * fs) / n : 0;
}

/** Torso lean from normalised image landmarks, as before. */
export function legacyTorsoAngle(lm, P) {
  const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, z: ((a.z || 0) + (b.z || 0)) / 2 });
  const sh = mid(lm[P.lSh], lm[P.rSh]);
  const hip = mid(lm[P.lHip], lm[P.rHip]);
  return deg(Math.atan2(sh.z - hip.z, Math.abs(hip.y - sh.y) || 1e-6));
}
