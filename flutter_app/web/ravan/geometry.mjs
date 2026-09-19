/**
 * Geometric primitives for the on-device feature extractor.
 *
 * Pure functions over landmark arrays. Tested against synthetic ground truth
 * by tools/accuracy/; see docs/12-accuracy.md for the measured error.
 */

import { clamp, deg } from './filters.mjs';

export const dist2 = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
export const dist3 = (a, b) => Math.hypot(a.x - b.x, a.y - b.y, (a.z || 0) - (b.z || 0));
export const mid = (a, b) => ({
  x: (a.x + b.x) / 2,
  y: (a.y + b.y) / 2,
  z: ((a.z || 0) + (b.z || 0)) / 2,
  visibility: Math.min(a.visibility ?? 1, b.visibility ?? 1),
});

/**
 * Head orientation from MediaPipe's facial transformation matrix.
 *
 * `data` is a 16-float **column-major** 4x4: data[c * 4 + r] is row r, column
 * c. The rotation is decomposed as R = Ry(yaw) · Rx(pitch) · Rz(roll), the
 * convention head-pose literature uses, so the three angles read as "turn",
 * "nod" and "tilt" rather than as an arbitrary triple.
 *
 * The previous implementation mixed a row of the matrix with a column while
 * taking the yaw magnitude, which biased yaw whenever pitch and roll were both
 * non-zero — the common case for someone sitting at a desk.
 *
 * Returns degrees. Near the gimbal singularity (|pitch| → 90°) yaw and roll are
 * not separable; roll is pinned to 0 and the combined rotation goes into yaw,
 * and `degenerate` says so.
 */
export function headPoseFromMatrix(data) {
  if (!data || data.length < 16) return null;
  const r = (row, col) => data[col * 4 + row];

  const r12 = clamp(r(1, 2), -1, 1);
  const pitch = Math.asin(-r12);
  const cosPitch = Math.sqrt(1 - r12 * r12);

  let yaw, roll, degenerate = false;
  if (cosPitch > 1e-6) {
    yaw = Math.atan2(r(0, 2), r(2, 2));
    roll = Math.atan2(r(1, 0), r(1, 1));
  } else {
    degenerate = true;
    yaw = Math.atan2(-r(2, 0), r(0, 0));
    roll = 0;
  }
  return { yaw: deg(yaw), pitch: deg(pitch), roll: deg(roll), degenerate };
}

/** Build a column-major Ry·Rx·Rz matrix. Used by the tests and by callers that
 *  need to go the other way; kept beside the decomposition so the two cannot
 *  drift apart. */
export function matrixFromHeadPose(yawDeg, pitchDeg, rollDeg) {
  const [a, b, c] = [yawDeg, pitchDeg, rollDeg].map((d) => (d * Math.PI) / 180);
  const [ca, sa, cb, sb, cc, sc] = [Math.cos(a), Math.sin(a), Math.cos(b), Math.sin(b), Math.cos(c), Math.sin(c)];
  const R = [
    [ca * cc + sa * sb * sc, -ca * sc + sa * sb * cc, sa * cb],
    [cb * sc, cb * cc, -sb],
    [-sa * cc + ca * sb * sc, sa * sc + ca * sb * cc, ca * cb],
  ];
  const out = new Array(16).fill(0);
  for (let col = 0; col < 3; col++) for (let row = 0; row < 3; row++) out[col * 4 + row] = R[row][col];
  out[15] = 1;
  return out;
}

/**
 * Eye aspect ratio (Soukupová & Čech, 2016), generalised to the MediaPipe face
 * mesh: the mean of two vertical lid openings over the horizontal corner
 * distance. Two vertical pairs rather than one make the ratio far less
 * sensitive to which single mesh point the model happens to place badly.
 */
export function eyeAspectRatio(lm, idx) {
  const h = dist2(lm[idx.inner], lm[idx.outer]);
  if (h < 1e-9) return null;
  const v1 = dist2(lm[idx.topA], lm[idx.bottomA]);
  const v2 = dist2(lm[idx.topB], lm[idx.bottomB]);
  return (v1 + v2) / (2 * h);
}

/**
 * Blink / eye-closure state machine.
 *
 * Two things the previous threshold-on-a-running-mean approach got wrong:
 *
 *  1. The mean it compared against included the blink itself, so a long or a
 *     rapid series of blinks dragged the threshold down and the later blinks
 *     went unseen.
 *  2. A single threshold chatters: one noisy frame at the boundary produced a
 *     pair of spurious blinks.
 *
 * Here the reference is a median over *open-eye frames only*, and closing and
 * opening use different thresholds (hysteresis). A closure is reported as a
 * blink inside `[minBlinkMs, maxBlinkMs]`; anything longer is a sustained
 * closure, which the catalog treats as a different signal.
 */
export class BlinkDetector {
  constructor({
    // minBlinkMs is 30, not the ~100 ms a physiological blink actually lasts:
    // the thresholds are crossed part-way into the lid dip, so the *detected*
    // closure is shorter than the real one, and at 30 fps a true 100 ms blink
    // can present as a single frame below threshold. Sweeping this against the
    // benchmark, 30 ms recovers every blink with no false positive at
    // realistic noise; 50 ms loses 8% of them.
    closeRatio = 0.62, openRatio = 0.75, minBlinkMs = 30, maxBlinkMs = 500,
    refractoryMs = 80, referenceWindow = 90,
  } = {}) {
    Object.assign(this, { closeRatio, openRatio, minBlinkMs, maxBlinkMs, refractoryMs });
    this.openSamples = [];
    this.referenceWindow = referenceWindow;
    this.closed = false;
    this.closedSince = 0;
    this.lastBlinkEnd = -Infinity;
    this.reference = null;
  }

  get ready() {
    return this.openSamples.length >= 10;
  }

  _updateReference(ear) {
    this.openSamples.push(ear);
    if (this.openSamples.length > this.referenceWindow) this.openSamples.shift();
    const a = [...this.openSamples].sort((p, q) => p - q);
    this.reference = a[a.length >> 1];
  }

  /**
   * @param {number} ear   current eye aspect ratio
   * @param {number} tMs   frame time in milliseconds
   * @returns {{blink:boolean, durationMs:number, closureS:number, closed:boolean}}
   */
  update(ear, tMs) {
    const idle = { blink: false, durationMs: 0, closureS: 0, closed: this.closed };
    if (!Number.isFinite(ear)) return idle;

    if (this.reference === null) {
      this._updateReference(ear);
      return idle;
    }
    const closeAt = this.closeRatio * this.reference;
    const openAt = this.openRatio * this.reference;

    if (!this.closed) {
      if (ear < closeAt && tMs - this.lastBlinkEnd >= this.refractoryMs) {
        this.closed = true;
        this.closedSince = tMs;
        return { blink: false, durationMs: 0, closureS: 0, closed: true };
      }
      // Only frames with the eye demonstrably open feed the reference.
      if (ear > openAt) this._updateReference(ear);
      return idle;
    }

    if (ear > openAt) {
      const durationMs = tMs - this.closedSince;
      this.closed = false;
      this.lastBlinkEnd = tMs;
      this._updateReference(ear);
      const blink = durationMs >= this.minBlinkMs && durationMs <= this.maxBlinkMs;
      return { blink, durationMs, closureS: 0, closed: false };
    }
    return { blink: false, durationMs: 0, closureS: (tMs - this.closedSince) / 1000, closed: true };
  }
}

/**
 * Torso and neck angles from MediaPipe's **world** landmarks.
 *
 * World landmarks are metric (metres, origin at the hip midpoint), so an angle
 * derived from them is a real angle. The normalised image landmarks the
 * previous version used carry a z that is only loosely calibrated relative to
 * x and y, which made `torso_angle` depend on how far the patient sat from the
 * camera — exactly the confound a per-person baseline cannot remove, because
 * people move closer and further during a session.
 *
 * lean  > 0 leaning towards the camera, < 0 away
 * list  > 0 leaning to the subject's left
 * twist > 0 shoulders rotated so the subject's right shoulder is further away
 */
export function torsoGeometry(world, P) {
  const need = [P.lSh, P.rSh, P.lHip, P.rHip];
  if (need.some((i) => !world[i])) return null;
  const sh = mid(world[P.lSh], world[P.rSh]);
  const hip = mid(world[P.lHip], world[P.rHip]);
  const up = Math.hypot(sh.y - hip.y, 1e-9);

  const lean = deg(Math.atan2(-(sh.z - hip.z), up));
  const list = deg(Math.atan2(sh.x - hip.x, up));
  const twist = deg(Math.atan2(world[P.rSh].z - world[P.lSh].z, world[P.rSh].x - world[P.lSh].x));

  const shoulderWidth = dist3(world[P.lSh], world[P.rSh]);
  let neckFlexion = null;
  if (world[P.lEar] && world[P.rEar]) {
    const ear = mid(world[P.lEar], world[P.rEar]);
    const rise = Math.hypot(ear.y - sh.y, 1e-9);
    neckFlexion = deg(Math.atan2(-(ear.z - sh.z), rise));
  }
  return { lean, list, twist, neckFlexion, shoulderWidth, shoulderMid: sh, hipMid: hip };
}

/**
 * Are the supporting landmarks trustworthy this frame?
 *
 * MediaPipe reports per-landmark visibility; a hand behind the torso or a hip
 * below the desk still gets coordinates, they are just invented. Features
 * built on invisible landmarks are dropped rather than sent, so the personal
 * baseline is never trained on a guess.
 */
export function visible(landmarks, indices, threshold = 0.6) {
  for (const i of indices) {
    const v = landmarks[i]?.visibility;
    if (v !== undefined && v < threshold) return false;
    if (!landmarks[i]) return false;
  }
  return true;
}

/** Mean visibility over the given landmarks, as a quality score in [0, 1]. */
export function meanVisibility(landmarks, indices) {
  let s = 0, n = 0;
  for (const i of indices) {
    const v = landmarks[i]?.visibility;
    if (v !== undefined) { s += v; n++; }
  }
  return n ? s / n : 0;
}

/**
 * Sharpness of a greyscale thumbnail as the variance of its Laplacian — the
 * standard blur estimate. A blurred frame gives landmark positions that look
 * like movement; this lets the analysis service gate on it instead of
 * receiving the constant 0 the previous version always sent.
 */
export function laplacianVariance(gray, w, h) {
  let sum = 0, sumSq = 0, n = 0;
  for (let y = 1; y < h - 1; y++) {
    for (let x = 1; x < w - 1; x++) {
      const i = y * w + x;
      const l = -4 * gray[i] + gray[i - 1] + gray[i + 1] + gray[i - w] + gray[i + w];
      sum += l;
      sumSq += l * l;
      n++;
    }
  }
  if (!n) return 0;
  const mean = sum / n;
  return sumSq / n - mean * mean;
}

export { clamp, deg };
