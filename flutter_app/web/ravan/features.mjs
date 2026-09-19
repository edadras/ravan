/**
 * Frame-level feature extraction.
 *
 * Takes landmark arrays (from MediaPipe in the browser, or from synthetic
 * ground truth in the accuracy harness) and produces the derived features
 * named in catalog/feature_dictionary.json.
 *
 * Two rules run through the whole file:
 *
 *  - **A feature is either measured or absent.** When the supporting landmarks
 *    are not visible, or a scale is degenerate, the key is left out of the
 *    payload rather than filled with a fallback. The analysis service builds a
 *    per-person baseline out of whatever arrives, so one invented number
 *    poisons every later comparison against it.
 *  - **Timing is real.** Velocities use the measured interval between the two
 *    frames actually processed, not the nominal frame rate. At 15 fps nominal
 *    a browser tab routinely delivers 9-22 fps, so a nominal-rate velocity is
 *    wrong by up to 60% and varies with the machine's load.
 */

import { LandmarkFilter, OneEuro, TimedRing, amplitude, clamp, deg, dominantFrequency } from './filters.mjs';
import {
  BlinkDetector, dist2, dist3, eyeAspectRatio, headPoseFromMatrix, laplacianVariance,
  meanVisibility, mid, torsoGeometry, visible,
} from './geometry.mjs';

/** Face-mesh landmark indices (MediaPipe canonical 478-point topology). */
export const F = {
  noseTip: 1, chin: 152, forehead: 10,
  lEyeOuter: 33, lEyeInner: 133, rEyeInner: 362, rEyeOuter: 263,
  lIris: 468, rIris: 473,
  lBrowInner: 107, lBrowMid: 105, lBrowOuter: 70,
  rBrowInner: 336, rBrowMid: 334, rBrowOuter: 300,
  lEyeTop: 159, lEyeBottom: 145, rEyeTop: 386, rEyeBottom: 374,
  mouthL: 61, mouthR: 291, upperLipTop: 13, lowerLipBottom: 14,
  upperLipOuter: 0, lowerLipOuter: 17, lCheek: 50, rCheek: 280,
};

/** Two vertical lid pairs per eye, as the eye-aspect-ratio literature uses. */
export const EYE = {
  left: { inner: 133, outer: 33, topA: 160, bottomA: 144, topB: 158, bottomB: 153 },
  right: { inner: 362, outer: 263, topA: 385, bottomA: 380, topB: 387, bottomB: 373 },
};

/** Pose landmark indices (MediaPipe BlazePose 33-point topology). */
export const P = {
  nose: 0, lEar: 7, rEar: 8, lSh: 11, rSh: 12, lEl: 13, rEl: 14,
  lWr: 15, rWr: 16, lHip: 23, rHip: 24, lKnee: 25, rKnee: 26, lAnk: 27, rAnk: 28,
};

export const AU_FROM_BLEND = {
  AU1: ['browInnerUp'], AU2: ['browOuterUpLeft', 'browOuterUpRight'], AU4: ['browDownLeft', 'browDownRight'],
  AU5: ['eyeWideLeft', 'eyeWideRight'], AU6: ['cheekSquintLeft', 'cheekSquintRight'], AU7: ['eyeSquintLeft', 'eyeSquintRight'],
  AU9: ['noseSneerLeft', 'noseSneerRight'], AU10: ['mouthUpperUpLeft', 'mouthUpperUpRight'], AU12: ['mouthSmileLeft', 'mouthSmileRight'],
  AU14: ['mouthDimpleLeft', 'mouthDimpleRight'], AU15: ['mouthFrownLeft', 'mouthFrownRight'], AU17: ['mouthShrugLower'],
  AU18: ['mouthPucker'], AU19: ['tongueOut'], AU20: ['mouthStretchLeft', 'mouthStretchRight'],
  AU23: ['mouthPressLeft', 'mouthPressRight'], AU24: ['mouthPressLeft', 'mouthPressRight', 'mouthClose'],
  AU25: ['jawOpen'], AU28: ['mouthRollLower', 'mouthRollUpper'], AU30: ['jawLeft', 'jawRight'],
};

const VISIBILITY_MIN = 0.6;

// One-Euro constants, chosen by sweeping minCutoff x beta against the
// noise/lag benchmark in tools/accuracy (see docs/12-accuracy.md). beta is
// large because landmark coordinates are normalised to [0, 1], so a fast hand
// moves at well under 1 unit/second and a small beta would never raise the
// cutoff. ANGLE_FILTER works in degrees, where speeds are two orders of
// magnitude larger, so its beta is correspondingly smaller.
const FACE_FILTER = { minCutoff: 1.0, beta: 10, dCutoff: 1.0 };
const POSE_FILTER = { minCutoff: 0.9, beta: 8, dCutoff: 1.0 };
const HAND_FILTER = { minCutoff: 1.5, beta: 16, dCutoff: 1.5 };
const ANGLE_FILTER = { minCutoff: 1.0, beta: 0.25, dCutoff: 1.0 };

/** Frames whose interval falls outside this range are treated as a gap, not a
 *  sample: a dt of 4 seconds after a tab was backgrounded is not a velocity. */
const DT_MIN = 0.008;
const DT_MAX = 0.4;

export class FaceExtractor {
  constructor(opts = {}) {
    this.filter = new LandmarkFilter(FACE_FILTER);
    this.yaw = new OneEuro(ANGLE_FILTER);
    this.pitch = new OneEuro(ANGLE_FILTER);
    this.roll = new OneEuro(ANGLE_FILTER);
    this.blink = new BlinkDetector(opts.blink);
    this.prev = null;
    this.headRing = { pitch: new TimedRing(180), yaw: new TimedRing(180) };
    this.gazePrev = null;
    this.spectralEvery = opts.spectralEvery ?? 15;
    this.frame = 0;
  }

  /**
   * @param {object} input
   *   landmarks   478 normalised face landmarks
   *   blendshapes {name: score} or undefined
   *   matrix      16-float facial transformation matrix or undefined
   *   tMs         frame time, milliseconds since session start
   *   dt          seconds since the previous processed frame
   *   videoWidth  pixel width, for the face-size quality gate
   */
  update({ landmarks, blendshapes = {}, matrix, tMs, dt, videoWidth = 640 }) {
    if (!landmarks || landmarks.length < 468) return null;
    const step = clamp(dt, DT_MIN, DT_MAX);
    const usable = dt >= DT_MIN && dt <= DT_MAX;
    const lm = this.filter.apply(landmarks, step);
    this.frame++;

    const features = {};
    const t = tMs / 1000;

    // ---- scale reference: inter-ocular distance. Every face measurement below
    // is divided by it, which makes them invariant to how far the patient sits
    // from the camera.
    const eyeSpan = dist2(lm[F.lEyeOuter], lm[F.rEyeOuter]);
    if (eyeSpan < 1e-6) return null;

    // ---- head orientation
    let pose = matrix ? headPoseFromMatrix(matrix) : null;
    if (pose && !pose.degenerate) {
      const yaw = this.yaw.filter(pose.yaw, step);
      const pitch = this.pitch.filter(pose.pitch, step);
      const roll = this.roll.filter(pose.roll, step);
      Object.assign(features, { head_yaw: yaw, head_pitch: pitch, head_roll: roll });
      this.headRing.pitch.push(t, pitch);
      this.headRing.yaw.push(t, yaw);
      if (this.prev?.pose && usable) {
        features.head_angular_velocity = Math.hypot(
          yaw - this.prev.pose.yaw, pitch - this.prev.pose.pitch, roll - this.prev.pose.roll,
        ) / step;
      }
      pose = { yaw, pitch, roll };
    } else {
      pose = null;
    }

    // ---- head translation, in inter-ocular units so it is comparable between
    // sessions and cameras
    const nose = lm[F.noseTip];
    features.head_tx = nose.x;
    features.head_ty = nose.y;
    features.head_scale = eyeSpan;
    if (this.prev && usable) {
      features.head_motion_energy = (Math.hypot(nose.x - this.prev.nose.x, nose.y - this.prev.nose.y) / eyeSpan) / step;
      features.face_landmark_motion_energy = features.head_motion_energy;
    }

    // ---- eyes
    const earL = eyeAspectRatio(lm, EYE.left);
    const earR = eyeAspectRatio(lm, EYE.right);
    if (earL !== null && earR !== null) {
      const earM = (earL + earR) / 2;
      Object.assign(features, {
        ear_left: earL, ear_right: earR, eye_aperture_mean: earM,
        eye_asymmetry: Math.abs(earL - earR) / earM,
      });
      const b = this.blink.update(earM, tMs);
      if (b.blink) {
        features.blink_event = true;
        features.blink_duration = b.durationMs;
      }
      if (b.closed && b.closureS > 0) features.eye_closure_duration = b.closureS;
    }

    // ---- gaze. The iris offset inside the eye box gives gaze *relative to the
    // head*; the head's own rotation is added separately so the two are not
    // silently mixed in different units, as they were before.
    const gaze = this._gaze(lm, pose);
    if (gaze) {
      Object.assign(features, gaze);
      if (this.gazePrev) {
        const shift = Math.hypot(gaze.gaze_x - this.gazePrev.x, gaze.gaze_y - this.gazePrev.y);
        if (shift > 0.22) features.gaze_shift_event = true;
      }
      this.gazePrev = { x: gaze.gaze_x, y: gaze.gaze_y };
    }

    // ---- brows, mouth, asymmetry
    const browH = (b, eTop) => (lm[eTop].y - lm[b].y) / eyeSpan;
    const bl = browH(F.lBrowMid, F.lEyeTop), br = browH(F.rBrowMid, F.rEyeTop);
    const mouthWidth = dist2(lm[F.mouthL], lm[F.mouthR]);
    Object.assign(features, {
      brow_height_left: bl, brow_height_right: br,
      brow_distance: dist2(lm[F.lBrowInner], lm[F.rBrowInner]) / eyeSpan,
      brow_asymmetry: Math.abs(bl - br),
      mouth_width: mouthWidth / eyeSpan,
      lip_thickness: (dist2(lm[F.upperLipOuter], lm[F.upperLipTop]) + dist2(lm[F.lowerLipOuter], lm[F.lowerLipBottom])) / eyeSpan,
    });
    if (mouthWidth > 1e-6) {
      features.mar = dist2(lm[F.upperLipTop], lm[F.lowerLipBottom]) / mouthWidth;
      features.mouth_corner_angle = deg(Math.atan2(
        -((lm[F.mouthL].y + lm[F.mouthR].y) / 2 - lm[F.upperLipTop].y), eyeSpan,
      ));
    }
    let asym = 0;
    for (const [a, b] of [[F.lEyeOuter, F.rEyeOuter], [F.mouthL, F.mouthR], [F.lCheek, F.rCheek]]) {
      asym += Math.abs(lm[a].y - lm[b].y) / eyeSpan;
    }
    features.face_asymmetry_index = asym / 3;

    // ---- action units from blendshapes
    let auTotal = 0;
    for (const [au, names] of Object.entries(AU_FROM_BLEND)) {
      let s = 0, n = 0;
      for (const name of names) { if (blendshapes[name] !== undefined) { s += blendshapes[name]; n++; } }
      if (n) { features[au] = s / n; auTotal += features[au]; }
    }
    if (Object.keys(blendshapes).length) {
      features.au_activity_total = auTotal;
      const sl = blendshapes.mouthSmileLeft ?? 0, sr = blendshapes.mouthSmileRight ?? 0;
      const peak = Math.max(sl, sr);
      // Symmetry is meaningless when neither corner is raised; reporting 0 there
      // would have looked like a strongly one-sided smile.
      if (peak > 0.05) features.smile_symmetry = Math.min(sl, sr) / peak;
      if (blendshapes.jawLeft !== undefined) features.jaw_lateral = blendshapes.jawLeft - (blendshapes.jawRight ?? 0);
    }

    this.prev = { nose: { x: nose.x, y: nose.y }, pose };

    // ---- quality
    const inFrame = lm.reduce((n, p) => n + (p.x >= 0 && p.x <= 1 && p.y >= 0 && p.y <= 1 ? 1 : 0), 0) / lm.length;
    const facePx = eyeSpan * videoWidth;
    const quality = {
      face_quality: clamp(inFrame * clamp(facePx / 90, 0, 1), 0, 1),
      face_in_frame_ratio: inFrame,
      face_size_px: facePx,
    };
    return { features, quality };
  }

  _gaze(lm, pose) {
    const iris = (irisIdx, inner, outer, top, bottom) => {
      const p = lm[irisIdx];
      if (!p) return null;
      const w = Math.abs(lm[outer].x - lm[inner].x);
      const h = Math.abs(lm[bottom].y - lm[top].y);
      if (w < 1e-6 || h < 1e-6) return null;
      return {
        x: (p.x - (lm[inner].x + lm[outer].x) / 2) / w,
        y: (p.y - (lm[top].y + lm[bottom].y) / 2) / h,
      };
    };
    const l = iris(F.lIris, F.lEyeInner, F.lEyeOuter, F.lEyeTop, F.lEyeBottom);
    const r = iris(F.rIris, F.rEyeInner, F.rEyeOuter, F.rEyeTop, F.rEyeBottom);
    if (!l || !r) return null;
    const inHead = { x: (l.x + r.x) / 2, y: (l.y + r.y) / 2 };
    // Eye-in-head offset is roughly ±0.5 of the eye half-width over ±25° of
    // ocular rotation, so scale it to degrees before adding the head's own
    // rotation. Without a per-user calibration this is a direction estimate,
    // not a point of regard — the catalog only ever uses it as one.
    const gazeDeg = { x: inHead.x * 50, y: inHead.y * 40 };
    if (pose) { gazeDeg.x += pose.yaw; gazeDeg.y += pose.pitch; }
    const nx = gazeDeg.x / 45, ny = gazeDeg.y / 45;
    return {
      gaze_x: nx, gaze_y: ny,
      gaze_in_head_x: inHead.x, gaze_in_head_y: inHead.y,
      gaze_on_screen: Math.abs(nx) < 0.45 && Math.abs(ny) < 0.45,
      gaze_on_camera: Math.abs(nx) < 0.15 && Math.abs(ny) < 0.15,
    };
  }

  /** Slow rhythms of the head (nodding, rocking). Recomputed every N frames. */
  headOscillation() {
    if (this.frame % this.spectralEvery !== 0) return null;
    const { t, v } = this.headRing.pitch.toArrays();
    return dominantFrequency(t, v, { fMin: 0.3, fMax: 4, minSeconds: 4 });
  }
}

export class PoseExtractor {
  constructor(opts = {}) {
    this.filter = new LandmarkFilter(POSE_FILTER);
    this.worldFilter = new LandmarkFilter({ ...POSE_FILTER, beta: 0.004 });
    this.prev = null;
    this.torso = new TimedRing(240);
    this.knee = new TimedRing(180);
    this.motion = new TimedRing(60);
    this.lastMoveT = 0;
    this.spectralEvery = opts.spectralEvery ?? 15;
    this.frame = 0;
  }

  /**
   * @param {object} input
   *   landmarks       33 normalised pose landmarks (image frame)
   *   worldLandmarks  33 metric pose landmarks (metres, hip-centred)
   *   tMs, dt         as in FaceExtractor
   */
  update({ landmarks, worldLandmarks, tMs, dt }) {
    if (!landmarks || landmarks.length < 33) return null;
    const step = clamp(dt, DT_MIN, DT_MAX);
    const usable = dt >= DT_MIN && dt <= DT_MAX;
    const lm = this.filter.apply(landmarks, step);
    const world = worldLandmarks?.length >= 33 ? this.worldFilter.apply(worldLandmarks, step) : null;
    this.frame++;
    const t = tMs / 1000;

    const features = {};
    const upper = [P.lSh, P.rSh, P.lHip, P.rHip];
    const upperOk = visible(lm, upper, VISIBILITY_MIN);

    // ---- metric torso geometry (needs world landmarks; without them the
    // angles would depend on distance from the camera, so they are omitted)
    if (upperOk && world) {
      const g = torsoGeometry(world, P);
      if (g) {
        features.torso_angle = g.lean;
        features.torso_lateral_angle = g.list;
        features.torso_yaw = g.twist;
        features.body_scale = g.shoulderWidth;
        if (g.neckFlexion !== null && visible(lm, [P.lEar, P.rEar], VISIBILITY_MIN)) {
          features.neck_flexion = g.neckFlexion;
        }
        this.torso.push(t, g.lean);
      }
    }

    if (upperOk) {
      const sh = mid(lm[P.lSh], lm[P.rSh]);
      const hip = mid(lm[P.lHip], lm[P.rHip]);
      const shW = dist2(lm[P.lSh], lm[P.rSh]);
      if (shW > 1e-6) {
        Object.assign(features, {
          shoulder_height_left: (hip.y - lm[P.lSh].y) / shW,
          shoulder_height_right: (hip.y - lm[P.rSh].y) / shW,
          shoulder_asymmetry: Math.abs(lm[P.lSh].y - lm[P.rSh].y) / shW,
          shoulder_width_ratio: shW,
          body_center_x: sh.x - 0.5,
          body_center_y: sh.y - 0.5,
        });
        if (visible(lm, [P.lEar, P.rEar], VISIBILITY_MIN)) {
          features.shoulder_neck_distance =
            ((dist2(lm[P.lSh], lm[P.lEar]) + dist2(lm[P.rSh], lm[P.rEar])) / 2) / shW;
        }
      }
      if (visible(lm, [P.lWr, P.rWr], VISIBILITY_MIN)) {
        features.arms_crossed_score = this._armsCrossed(lm, sh, shW);
      }
      if (this.prev && usable) {
        const hipPrev = mid(this.prev[P.lHip], this.prev[P.rHip]);
        if (dist2(hip, hipPrev) / step > 0.3) features.hip_shift_event = true;
        if (hipPrev.y - hip.y > 0.15) features.standing_event = true;
      }
    }

    // ---- movement energy, measured in metres per second when world landmarks
    // are available and in body widths per second otherwise
    const key = [P.nose, P.lSh, P.rSh, P.lEl, P.rEl, P.lWr, P.rWr, P.lHip, P.rHip];
    const tracked = key.filter((i) => (lm[i]?.visibility ?? 1) >= VISIBILITY_MIN);
    if (this.prev && usable && tracked.length >= 5) {
      const src = world ?? lm;
      const prevSrc = this.prevWorld ?? this.prev;
      let delta = 0;
      for (const i of tracked) delta += dist3(src[i], prevSrc[i]);
      delta = (delta / tracked.length) / step;
      features.pose_delta = delta;
      this.motion.push(t, delta);
      features.motion_energy_total = this.motion.mean();
      if (delta > 0.25) features.pose_change_event = true;
      if (delta > 1.2) features.sudden_motion_event = true;
      if (delta > 0.08) this.lastMoveT = t;
      features.stillness_duration = t - this.lastMoveT;

      const armMotion = (el, wr) =>
        ((lm[el]?.visibility ?? 0) >= VISIBILITY_MIN && (lm[wr]?.visibility ?? 0) >= VISIBILITY_MIN)
          ? dist3(src[el], prevSrc[el]) + dist3(src[wr], prevSrc[wr]) : null;
      const left = armMotion(P.lEl, P.lWr), right = armMotion(P.rEl, P.rWr);
      if (left !== null && right !== null) {
        const peak = Math.max(left, right);
        if (peak > 1e-6) features.motion_symmetry = Math.min(left, right) / peak;
      }
    }

    // ---- lower body: only when it is actually in shot. A patient at a desk
    // usually has no legs in frame; the previous version still reported a knee
    // oscillation of 0 Hz, which the baseline learned as "never fidgets".
    const lowerVisible = visible(lm, [P.lHip, P.rHip, P.lKnee, P.rKnee], VISIBILITY_MIN);
    features.lower_body_visible = lowerVisible;
    if (lowerVisible) {
      this.knee.push(t, (lm[P.lKnee].y + lm[P.rKnee].y) / 2);
      const osc = this.frame % this.spectralEvery === 0 ? this._spectral(this.knee, 0.5, 8) : null;
      if (osc) {
        features.knee_oscillation_hz = osc.hz;
        features.knee_oscillation_prominence = osc.prominence;
      }
    } else {
      this.knee.clear();
    }

    if (this.frame % this.spectralEvery === 0) {
      const osc = this._spectral(this.torso, 0.15, 3);
      if (osc) {
        features.torso_oscillation_hz = osc.hz;
        features.torso_oscillation_prominence = osc.prominence;
      }
    }

    this.prev = lm;
    this.prevWorld = world;

    const quality = {
      pose_quality: meanVisibility(lm, key),
      persons_detected: 1,
      lower_body_visible: lowerVisible,
      landmarks_tracked: tracked.length,
    };
    return { features, quality };
  }

  _spectral(ring, fMin, fMax) {
    const { t, v } = ring.toArrays();
    return dominantFrequency(t, v, { fMin, fMax, minSeconds: 4 });
  }

  _armsCrossed(lm, sh, shW) {
    // Both wrists past the opposite shoulder, at chest height, in shoulder-width
    // units so it does not depend on distance from the camera. Graded rather
    // than binary: half-folded arms are common and a 0/1 flag threw away the
    // difference.
    const cross = (wr, sign) => clamp((sign * (lm[wr].x - sh.x)) / (shW * 0.5), 0, 1);
    const height = (wr) => clamp(1 - Math.abs(lm[wr].y - sh.y) / (shW * 1.2), 0, 1);
    const l = cross(P.lWr, 1) * height(P.lWr);
    const r = cross(P.rWr, -1) * height(P.rWr);
    return Math.min(l, r);
  }
}

export class HandExtractor {
  constructor() {
    this.filters = { left: new LandmarkFilter(HAND_FILTER), right: new LandmarkFilter(HAND_FILTER) };
    this.prev = {};
    this.rings = { left: new TimedRing(120), right: new TimedRing(120) };
    this.lastRegion = 'none';
    this.frame = 0;
  }

  /**
   * @param {object} input
   *   hands       array of {landmarks, side:'left'|'right', score}
   *   faceLm      face landmarks, or null
   *   poseLm      pose landmarks, or null
   *   tMs, dt
   */
  update({ hands = [], faceLm = null, poseLm = null, tMs, dt }) {
    const step = clamp(dt, DT_MIN, DT_MAX);
    const usable = dt >= DT_MIN && dt <= DT_MAX;
    this.frame++;
    const t = tMs / 1000;

    if (!hands.length) {
      this.lastRegion = 'none';
      return { features: { hands_visible: false }, quality: { hand_quality: 0, hands_visible: false } };
    }

    const features = { hands_visible: true };
    // Contact regions are measured in face widths, so "hand at the mouth" means
    // the same thing at any distance from the camera.
    const faceScale = faceLm ? dist2(faceLm[F.lEyeOuter], faceLm[F.rEyeOuter]) : null;
    const regions = {};
    if (faceLm && faceScale > 1e-6) {
      Object.assign(regions, {
        mouth: mid(faceLm[F.mouthL], faceLm[F.mouthR]),
        nose: faceLm[F.noseTip],
        eye: mid(faceLm[F.lEyeOuter], faceLm[F.rEyeOuter]),
        forehead: faceLm[F.forehead],
        chin: faceLm[F.chin],
      });
    }
    if (poseLm && visible(poseLm, [P.lSh, P.rSh], VISIBILITY_MIN)) {
      regions.chest = mid(poseLm[P.lSh], poseLm[P.rSh]);
      if (faceLm) regions.neck = mid(regions.chest, faceLm[F.chin]);
    }

    let fingerEnergy = 0, cover = 0, quality = 0, nearest = Infinity, nearestRegion = 'none';
    let pointing = 0, gesture = false, bestHz = null;

    for (const hand of hands) {
      const side = hand.side;
      const lm = this.filters[side].apply(hand.landmarks, step);
      const wrist = lm[0], indexTip = lm[8], middleTip = lm[12];
      const prev = this.prev[side];

      if (prev && usable) {
        features[`hand_velocity_${side}`] = dist3(wrist, prev[0]) / step;
        let fe = 0;
        for (const j of [4, 8, 12, 16, 20]) fe += dist3(lm[j], prev[j]);
        fingerEnergy += fe / 5 / step;
      }
      this.prev[side] = lm;
      this.rings[side].push(t, wrist.y);

      if (faceScale) {
        let best = 'none', bestD = Infinity;
        for (const [name, pt] of Object.entries(regions)) {
          const d = Math.min(dist2(indexTip, pt), dist2(middleTip, pt), dist2(wrist, pt)) / (faceScale * 2.5);
          if (d < bestD) { bestD = d; best = name; }
        }
        if (Number.isFinite(bestD)) {
          features[`hand_face_distance_${side}`] = bestD;
          if (bestD < nearest) { nearest = bestD; nearestRegion = bestD < 0.6 ? best : 'none'; }
          if (bestD < 0.5) cover += 0.4;
        }
      }

      const spread = dist3(lm[12], wrist) + dist3(lm[16], wrist) + dist3(lm[20], wrist);
      if (spread > 1e-6) {
        pointing = Math.max(pointing, clamp((dist3(indexTip, wrist) * 3) / spread - 0.9, 0, 1));
      }
      const v = features[`hand_velocity_${side}`];
      if (v !== undefined && v > 0.6 && (features[`hand_face_distance_${side}`] ?? 1) > 0.8) gesture = true;

      if (this.frame % 15 === 0) {
        const { t: ts, v: vs } = this.rings[side].toArrays();
        // Physiological and drug-induced tremor sit between roughly 3 and 12 Hz.
        // dominantFrequency caps this at 0.45 of the real frame rate, so at a
        // 15 fps hand stream only the lower half of that range is observable.
        const osc = dominantFrequency(ts, vs, { fMin: 2, fMax: 12, minSeconds: 3, minProminence: 0.30 });
        if (osc && (!bestHz || osc.prominence > bestHz.prominence)) {
          bestHz = { ...osc, amplitude: amplitude(vs) };
        }
      }
      quality += hand.score ?? 0.5;
    }

    if (hands.length) features.finger_motion_energy = fingerEnergy / hands.length;
    if (pointing > 0) features.pointing_score = pointing;
    if (gesture) features.gesture_event = true;
    if (cover > 0) features.hand_covering_face_score = clamp(cover, 0, 1);
    if (bestHz) {
      features.hand_oscillation_hz = bestHz.hz;
      features.hand_oscillation_amplitude = bestHz.amplitude;
      features.hand_oscillation_prominence = bestHz.prominence;
    }
    if (hands.length === 2 && faceScale) {
      features.hands_clasped_score = dist3(hands[0].landmarks[0], hands[1].landmarks[0]) < faceScale * 1.5 ? 1 : 0;
    }
    if (faceLm && regions.chin && nearest < 0.35) {
      const toChin = Math.min(...hands.map((h) => dist2(h.landmarks[0], regions.chin)));
      if (toChin < faceScale * 1.2) features.head_support_score = 0.7;
    }
    if (nearestRegion !== 'none') {
      features.hand_region_contact = nearestRegion;
      if (nearestRegion !== this.lastRegion) features.hand_contact_event = true;
    }
    this.lastRegion = nearestRegion;

    return {
      features,
      quality: { hand_quality: quality / hands.length, hands_visible: true, hands_detected: hands.length },
    };
  }
}

/**
 * Frame-quality measures from a small greyscale thumbnail.
 *
 * The previous version sent a constant `motion_blur: 0` and
 * `glasses_detected: false`, so the catalog's quality gates on those could
 * never fire. Blur and illumination are now measured; `glasses_detected` and
 * `mask_detected` are simply not sent, because nothing here can determine them
 * and an always-false answer is worse than no answer.
 */
export function frameQuality(gray, w, h, { previousHash = null } = {}) {
  let sum = 0, hash = 0;
  for (let i = 0; i < gray.length; i++) {
    sum += gray[i];
    hash = (hash * 31 + gray[i]) | 0;
  }
  const mean = sum / gray.length;
  let variance = 0;
  for (let i = 0; i < gray.length; i++) variance += (gray[i] - mean) ** 2;
  variance /= gray.length;

  const sharpness = laplacianVariance(gray, w, h);
  return {
    illumination: clamp(mean / 255, 0, 1),
    illumination_uniformity: clamp(1 - Math.sqrt(variance) / 128, 0, 1),
    // Normalised so 1 is a sharp frame and 0 a smeared one; the knee at ~120 is
    // where a 64x64 thumbnail of a webcam frame stops resolving facial edges.
    motion_blur: clamp(1 - sharpness / 120, 0, 1),
    frame_sharpness: sharpness,
    frozen: previousHash !== null && hash === previousHash,
    hash,
  };
}
