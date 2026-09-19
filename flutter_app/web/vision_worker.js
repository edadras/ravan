/**
 * Ravan on-device vision feature extractor.
 *
 * Runs MediaPipe Face / Pose / Hand landmarkers on the *local* camera stream and
 * converts landmarks into the derived features listed in catalog/feature_dictionary.json.
 * Only these numbers are sent to the analysis service (WebSocket). No pixels leave the
 * browser except through the encrypted WebRTC call itself.
 *
 * Public API (window.RavanVision):
 *   start({ videoEl, wsUrl, token, fps, onStatus })   -> Promise<void>
 *   setSpeakerState('patient_speaking' | 'patient_listening' | 'silence')
 *   pause() / resume() / stop()
 */
import {
  FaceLandmarker, PoseLandmarker, HandLandmarker, FilesetResolver,
} from "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14";

const WASM = "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/wasm";
const MODELS = {
  face: "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task",
  pose: "https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_lite/float16/1/pose_landmarker_lite.task",
  hand: "https://storage.googleapis.com/mediapipe-models/hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task",
};

// Face-mesh indices (MediaPipe canonical topology)
const F = {
  noseTip: 1, chin: 152, forehead: 10,
  lEyeOuter: 33, lEyeInner: 133, rEyeInner: 362, rEyeOuter: 263,
  lEyeTop: 159, lEyeBottom: 145, rEyeTop: 386, rEyeBottom: 374,
  lIris: 468, rIris: 473,
  lBrowInner: 107, lBrowMid: 105, lBrowOuter: 70, rBrowInner: 336, rBrowMid: 334, rBrowOuter: 300,
  mouthL: 61, mouthR: 291, upperLipTop: 13, lowerLipBottom: 14, upperLipOuter: 0, lowerLipOuter: 17,
  lCheek: 50, rCheek: 280,
};
const P = { nose: 0, lEar: 7, rEar: 8, lSh: 11, rSh: 12, lEl: 13, rEl: 14, lWr: 15, rWr: 16, lHip: 23, rHip: 24, lKnee: 25, rKnee: 26, lAnk: 27, rAnk: 28 };
const AU_FROM_BLEND = {
  AU1: ["browInnerUp"], AU2: ["browOuterUpLeft", "browOuterUpRight"], AU4: ["browDownLeft", "browDownRight"],
  AU5: ["eyeWideLeft", "eyeWideRight"], AU6: ["cheekSquintLeft", "cheekSquintRight"], AU7: ["eyeSquintLeft", "eyeSquintRight"],
  AU9: ["noseSneerLeft", "noseSneerRight"], AU10: ["mouthUpperUpLeft", "mouthUpperUpRight"], AU12: ["mouthSmileLeft", "mouthSmileRight"],
  AU14: ["mouthDimpleLeft", "mouthDimpleRight"], AU15: ["mouthFrownLeft", "mouthFrownRight"], AU17: ["mouthShrugLower"],
  AU18: ["mouthPucker"], AU20: ["mouthStretchLeft", "mouthStretchRight"], AU23: ["mouthPressLeft", "mouthPressRight"],
  AU24: ["mouthPressLeft", "mouthPressRight", "mouthClose"], AU25: ["jawOpen"], AU28: ["mouthRollLower", "mouthRollUpper"],
  AU30: ["jawLeft", "jawRight"], AU19: ["tongueOut"],
};

const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y, (a.z || 0) - (b.z || 0));
const mid = (a, b) => ({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, z: ((a.z || 0) + (b.z || 0)) / 2 });
const deg = (r) => (r * 180) / Math.PI;
const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

class RingStat {
  constructor(n) { this.n = n; this.buf = []; }
  push(v) { this.buf.push(v); if (this.buf.length > this.n) this.buf.shift(); }
  mean() { return this.buf.length ? this.buf.reduce((a, b) => a + b, 0) / this.buf.length : 0; }
  last() { return this.buf[this.buf.length - 1]; }
  // dominant oscillation frequency (Hz) of the buffered signal, given sample rate fs
  dominantHz(fs) {
    const x = this.buf; const n = x.length; if (n < 16) return 0;
    const m = x.reduce((a, b) => a + b, 0) / n; let best = 0, bestP = 0;
    for (let k = 1; k < n / 2; k++) { let re = 0, im = 0; for (let i = 0; i < n; i++) { const a = (2 * Math.PI * k * i) / n; re += (x[i] - m) * Math.cos(a); im -= (x[i] - m) * Math.sin(a); } const p = re * re + im * im; if (p > bestP) { bestP = p; best = k; } }
    const total = x.reduce((s, v) => s + (v - m) * (v - m), 0) * n;
    return total > 0 && bestP / total > 0.25 ? (best * fs) / n : 0;
  }
}

class VisionWorker {
  constructor() { this.running = false; this.paused = false; this.speakerState = "patient_listening"; this.t0 = 0; }

  async start({ videoEl, wsUrl, token, fps = 15, onStatus = () => {} }) {
    this.video = videoEl; this.fps = fps; this.onStatus = onStatus;
    const vision = await FilesetResolver.forVisionTasks(WASM);
    this.face = await FaceLandmarker.createFromOptions(vision, { baseOptions: { modelAssetPath: MODELS.face, delegate: "GPU" }, runningMode: "VIDEO", numFaces: 2, outputFaceBlendshapes: true, outputFacialTransformationMatrixes: true });
    this.pose = await PoseLandmarker.createFromOptions(vision, { baseOptions: { modelAssetPath: MODELS.pose, delegate: "GPU" }, runningMode: "VIDEO", numPoses: 2 });
    this.hand = await HandLandmarker.createFromOptions(vision, { baseOptions: { modelAssetPath: MODELS.hand, delegate: "GPU" }, runningMode: "VIDEO", numHands: 2 });
    this.canvas = document.createElement("canvas"); this.canvas.width = 64; this.canvas.height = 64; this.ctx = this.canvas.getContext("2d", { willReadFrequently: true });
    this.ws = new WebSocket(`${wsUrl}?token=${encodeURIComponent(token)}`);
    this.ws.onopen = () => onStatus({ state: "connected" });
    this.ws.onclose = () => onStatus({ state: "disconnected" });
    this.ws.onmessage = (m) => { try { const d = JSON.parse(m.data); if (d.type === "events") onStatus({ state: "events", events: d.events }); } catch (_) { } };
    this.t0 = performance.now(); this.running = true;
    this.prev = {}; this.earHist = new RingStat(8); this.blinkClosed = false; this.blinkStart = 0; this.eyeClosedSince = 0;
    this.headPitchHist = new RingStat(fps * 3); this.headYawHist = new RingStat(fps * 3); this.torsoHist = new RingStat(fps * 8);
    this.handHist = { left: new RingStat(fps * 3), right: new RingStat(fps * 3) }; this.kneeHist = new RingStat(fps * 4);
    this.motionHist = new RingStat(fps * 2); this.lastMoveT = 0; this.frameCount = 0; this.lastFrameHash = 0; this.sameFrames = 0;
    this.loop();
  }

  setSpeakerState(s) { this.speakerState = s; }
  pause() { this.paused = true; this.onStatus({ state: "paused" }); }
  resume() { this.paused = false; this.onStatus({ state: "resumed" }); }
  stop() { this.running = false; try { this.ws?.close(); } catch (_) { } this.face?.close(); this.pose?.close(); this.hand?.close(); }

  loop() {
    if (!this.running) return;
    const interval = 1000 / this.fps;
    const tick = () => {
      if (!this.running) return;
      const now = performance.now();
      if (!this.paused && this.video.readyState >= 2 && (!this.lastTick || now - this.lastTick >= interval)) {
        this.lastTick = now;
        try { this.processFrame(now); } catch (e) { console.warn("vision frame failed", e); }
      }
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  send(source, features, quality) {
    if (this.ws?.readyState !== 1) return;
    this.ws.send(JSON.stringify({ type: "frame", data: { t_ms: Math.round(performance.now() - this.t0), source, features, quality, speaker_state: this.speakerState } }));
  }

  processFrame(now) {
    const v = this.video; const tMs = Math.round(now - this.t0);
    // ---- quality: illumination & frozen-frame check from a 64x64 thumbnail
    this.ctx.drawImage(v, 0, 0, 64, 64);
    const px = this.ctx.getImageData(0, 0, 64, 64).data; let sum = 0, hash = 0;
    for (let i = 0; i < px.length; i += 16) { sum += px[i] + px[i + 1] + px[i + 2]; hash = (hash * 31 + px[i]) | 0; }
    const illumination = clamp(sum / (px.length / 16) / 765, 0, 1);
    if (hash === this.lastFrameHash) this.sameFrames++; else this.sameFrames = 0; this.lastFrameHash = hash;
    const fpsEff = this.lastFrameT ? 1000 / (now - this.lastFrameT) : this.fps; this.lastFrameT = now;

    // ---- face
    const fr = this.face.detectForVideo(v, now);
    const faces = fr.faceLandmarks?.length || 0;
    const fq = faces ? this.faceFeatures(fr, tMs, fpsEff) : null;
    const faceQuality = { face_quality: faces ? clamp(fq.q, 0, 1) : 0, faces_detected: faces, illumination, face_in_frame_ratio: faces ? fq.inFrame : 0, face_size_px: faces ? fq.sizePx : 0, fps: fpsEff, motion_blur: 0, glasses_detected: false, mask_detected: false };
    if (fq) this.send("face", fq.features, faceQuality); else this.send("quality", {}, faceQuality);

    // ---- pose
    const pr = this.pose.detectForVideo(v, now);
    const persons = pr.landmarks?.length || 0;
    if (persons) { const pf = this.poseFeatures(pr.landmarks[0], fpsEff); this.send("pose", pf.features, { pose_quality: pf.q, persons_detected: persons, lower_body_visible: pf.lowerVisible, fps: fpsEff }); }
    else this.send("quality", {}, { pose_quality: 0, persons_detected: 0 });

    // ---- hands
    const hr = this.hand.detectForVideo(v, now);
    const hf = this.handFeatures(hr, fr.faceLandmarks?.[0], pr.landmarks?.[0], fpsEff);
    this.send("hands", hf.features, { hand_quality: hf.q, hands_visible: hf.visible });

    if (this.sameFrames > this.fps * 3) this.send("quality", {}, { fps: 0 });
    this.frameCount++;
  }

  faceFeatures(fr, tMs, fps) {
    const lm = fr.faceLandmarks[0]; const bs = {}; (fr.faceBlendshapes?.[0]?.categories || []).forEach((c) => (bs[c.categoryName] = c.score));
    const M = fr.facialTransformationMatrixes?.[0]?.data; let yaw = 0, pitch = 0, roll = 0;
    if (M) { // column-major 4x4
      yaw = deg(Math.atan2(-M[8], Math.hypot(M[0], M[4]))); pitch = deg(Math.atan2(M[9], M[10])); roll = deg(Math.atan2(M[4], M[0]));
    }
    const eyeW = dist(lm[F.lEyeOuter], lm[F.rEyeOuter]);
    const ear = (top, bottom, inner, outer) => dist(lm[top], lm[bottom]) / (dist(lm[inner], lm[outer]) || 1e-6);
    const earL = ear(F.lEyeTop, F.lEyeBottom, F.lEyeInner, F.lEyeOuter), earR = ear(F.rEyeTop, F.rEyeBottom, F.rEyeInner, F.rEyeOuter);
    const earM = (earL + earR) / 2; this.earHist.push(earM);
    // blink: EAR dips below 60% of recent mean
    const thr = 0.6 * (this.earHist.mean() || earM); let blink = false, blinkDur = 0;
    if (earM < thr && !this.blinkClosed) { this.blinkClosed = true; this.blinkStart = tMs; }
    else if (earM >= thr && this.blinkClosed) { this.blinkClosed = false; blinkDur = tMs - this.blinkStart; blink = blinkDur < 500; }
    const eyeClosedDur = this.blinkClosed ? (tMs - this.blinkStart) / 1000 : 0;
    // gaze from iris position within eye box
    const gx = (i, inner, outer) => (lm[i].x - (lm[inner].x + lm[outer].x) / 2) / (Math.abs(lm[outer].x - lm[inner].x) || 1e-6);
    const gy = (i, top, bottom) => (lm[i].y - (lm[top].y + lm[bottom].y) / 2) / (Math.abs(lm[bottom].y - lm[top].y) || 1e-6);
    const gazeX = (gx(F.lIris, F.lEyeInner, F.lEyeOuter) + gx(F.rIris, F.rEyeInner, F.rEyeOuter)) / 2 + yaw / 45;
    const gazeY = (gy(F.lIris, F.lEyeTop, F.lEyeBottom) + gy(F.rIris, F.rEyeTop, F.rEyeBottom)) / 2 + pitch / 45;
    const prevG = this.prev.gaze || { x: gazeX, y: gazeY }; const gazeShift = Math.hypot(gazeX - prevG.x, gazeY - prevG.y) > 0.25; this.prev.gaze = { x: gazeX, y: gazeY };
    const browH = (b, eTop) => (lm[eTop].y - lm[b].y) / eyeW;
    const mar = dist(lm[F.upperLipTop], lm[F.lowerLipBottom]) / (dist(lm[F.mouthL], lm[F.mouthR]) || 1e-6);
    const au = {}; let auTotal = 0; for (const [k, names] of Object.entries(AU_FROM_BLEND)) { au[k] = names.reduce((s, n) => s + (bs[n] || 0), 0) / names.length; auTotal += au[k]; }
    const smileL = bs.mouthSmileLeft || 0, smileR = bs.mouthSmileRight || 0;
    const scale = eyeW; const tx = lm[F.noseTip].x, ty = lm[F.noseTip].y;
    const prevH = this.prev.head || { yaw, pitch, roll, tx, ty }; const dt = 1 / fps;
    const angVel = Math.hypot(yaw - prevH.yaw, pitch - prevH.pitch, roll - prevH.roll) / dt;
    const motion = Math.hypot(tx - prevH.tx, ty - prevH.ty) / dt; this.prev.head = { yaw, pitch, roll, tx, ty };
    this.headPitchHist.push(pitch); this.headYawHist.push(yaw);
    let asym = 0; for (const [l, r] of [[F.lEyeOuter, F.rEyeOuter], [F.mouthL, F.mouthR], [F.lCheek, F.rCheek]]) asym += Math.abs((lm[l].y - lm[r].y)) / eyeW; asym /= 3;
    const inFrame = lm.filter((p) => p.x >= 0 && p.x <= 1 && p.y >= 0 && p.y <= 1).length / lm.length;
    const sizePx = eyeW * (this.video.videoWidth || 640) * 2.4;
    return {
      q: clamp(0.5 + inFrame * 0.5, 0, 1) * (sizePx > 120 ? 1 : 0.5), inFrame, sizePx,
      features: {
        head_yaw: yaw, head_pitch: pitch, head_roll: roll, head_tx: tx, head_ty: ty, head_scale: scale,
        head_angular_velocity: angVel, head_motion_energy: motion,
        gaze_x: gazeX, gaze_y: gazeY, gaze_on_screen: Math.abs(gazeX) < 0.45 && Math.abs(gazeY) < 0.45, gaze_on_camera: Math.abs(gazeX) < 0.15 && Math.abs(gazeY) < 0.15,
        gaze_shift_event: gazeShift, ear_left: earL, ear_right: earR, blink_event: blink, blink_duration: blinkDur, eye_closure_duration: eyeClosedDur,
        eye_aperture_mean: earM, eye_asymmetry: Math.abs(earL - earR) / (earM || 1e-6),
        brow_height_left: browH(F.lBrowMid, F.lEyeTop), brow_height_right: browH(F.rBrowMid, F.rEyeTop), brow_distance: dist(lm[F.lBrowInner], lm[F.rBrowInner]) / eyeW,
        brow_asymmetry: Math.abs(browH(F.lBrowMid, F.lEyeTop) - browH(F.rBrowMid, F.rEyeTop)),
        mar, mouth_width: dist(lm[F.mouthL], lm[F.mouthR]) / eyeW, lip_thickness: dist(lm[F.upperLipOuter], lm[F.upperLipTop]) / eyeW + dist(lm[F.lowerLipOuter], lm[F.lowerLipBottom]) / eyeW,
        mouth_corner_angle: deg(Math.atan2(-((lm[F.mouthL].y + lm[F.mouthR].y) / 2 - lm[F.upperLipTop].y), eyeW)), smile_symmetry: Math.min(smileL, smileR) / (Math.max(smileL, smileR) || 1e-6),
        jaw_lateral: (bs.jawLeft || 0) - (bs.jawRight || 0),
        au_activity_total: auTotal, face_asymmetry_index: asym, face_landmark_motion_energy: motion,
        ...au,
      },
    };
  }

  poseFeatures(lm, fps) {
    const sh = mid(lm[P.lSh], lm[P.rSh]), hip = mid(lm[P.lHip], lm[P.rHip]);
    const torsoAngle = deg(Math.atan2(sh.z - hip.z, Math.abs(hip.y - sh.y) || 1e-6)); // forward (+) / back (-)
    const torsoLateral = deg(Math.atan2(sh.x - hip.x, Math.abs(hip.y - sh.y) || 1e-6));
    const torsoYaw = deg(Math.atan2(lm[P.rSh].z - lm[P.lSh].z, lm[P.rSh].x - lm[P.lSh].x));
    const ear = mid(lm[P.lEar], lm[P.rEar]);
    const neckFlex = deg(Math.atan2(ear.z - sh.z, Math.abs(sh.y - ear.y) || 1e-6));
    const shW = dist(lm[P.lSh], lm[P.rSh]) || 1e-6;
    const shNeck = ((dist(lm[P.lSh], lm[P.lEar]) + dist(lm[P.rSh], lm[P.rEar])) / 2) / shW;
    const wristL = lm[P.lWr], wristR = lm[P.rWr];
    const armsCrossed = (wristL.x > sh.x && wristR.x < sh.x && Math.abs(wristL.y - sh.y) < 0.35 && Math.abs(wristR.y - sh.y) < 0.35 && (wristL.visibility || 0) > 0.5 && (wristR.visibility || 0) > 0.5) ? 1 : 0;
    const key = [P.nose, P.lSh, P.rSh, P.lEl, P.rEl, P.lWr, P.rWr, P.lHip, P.rHip];
    const prev = this.prev.pose; let delta = 0;
    if (prev) { for (const i of key) delta += dist(lm[i], prev[i]); delta = (delta / key.length) * fps; }
    this.prev.pose = lm.map((p) => ({ ...p }));
    this.motionHist.push(delta); const motionEnergy = this.motionHist.mean();
    const poseChange = delta > 0.25; const now = performance.now(); if (delta > 0.08) this.lastMoveT = now;
    this.torsoHist.push(torsoAngle);
    const lowerVisible = [P.lHip, P.rHip, P.lKnee, P.rKnee].every((i) => (lm[i].visibility || 0) > 0.5);
    if (lowerVisible) this.kneeHist.push(lm[P.lKnee].y + lm[P.rKnee].y);
    const vis = key.reduce((s, i) => s + (lm[i].visibility || 0), 0) / key.length;
    const leftMotion = prev ? dist(lm[P.lWr], prev[P.lWr]) + dist(lm[P.lEl], prev[P.lEl]) : 0, rightMotion = prev ? dist(lm[P.rWr], prev[P.rWr]) + dist(lm[P.rEl], prev[P.rEl]) : 0;
    return {
      q: vis, lowerVisible,
      features: {
        torso_angle: torsoAngle, torso_lateral_angle: torsoLateral, torso_yaw: torsoYaw, neck_flexion: neckFlex,
        shoulder_height_left: hip.y - lm[P.lSh].y, shoulder_height_right: hip.y - lm[P.rSh].y, shoulder_asymmetry: Math.abs(lm[P.lSh].y - lm[P.rSh].y) / shW,
        shoulder_neck_distance: shNeck, shoulder_width_ratio: shW, body_center_x: sh.x - 0.5, body_center_y: sh.y - 0.5, body_scale: shW,
        pose_delta: delta, pose_change_event: poseChange, torso_oscillation_hz: this.torsoHist.dominantHz(fps), motion_energy_total: motionEnergy,
        motion_symmetry: Math.min(leftMotion, rightMotion) / (Math.max(leftMotion, rightMotion) || 1e-6), stillness_duration: (now - this.lastMoveT) / 1000,
        sudden_motion_event: delta > 1.2, arms_crossed_score: armsCrossed, lower_body_visible: lowerVisible,
        knee_oscillation_hz: lowerVisible ? this.kneeHist.dominantHz(fps) : 0, hip_shift_event: prev ? dist(hip, mid(prev[P.lHip], prev[P.rHip])) * fps > 0.3 : false,
        standing_event: prev ? (mid(prev[P.lHip], prev[P.rHip]).y - hip.y) > 0.15 : false,
      },
    };
  }

  handFeatures(hr, faceLm, poseLm, fps) {
    const hands = hr.landmarks || []; const handed = hr.handedness || [];
    const out = { hands_visible: hands.length > 0, hand_contact_event: false, hand_region_contact: "none", hand_face_distance_left: 1, hand_face_distance_right: 1,
      hand_velocity_left: 0, hand_velocity_right: 0, finger_motion_energy: 0, hands_clasped_score: 0, hand_covering_face_score: 0, head_support_score: 0,
      gesture_event: false, hand_oscillation_hz: 0, hand_oscillation_amplitude: 0, pointing_score: 0, palm_orientation: "unknown" };
    if (!hands.length) return { q: 0, visible: false, features: out };
    const regions = faceLm ? { mouth: mid(faceLm[F.mouthL], faceLm[F.mouthR]), nose: faceLm[F.noseTip], eye: mid(faceLm[F.lEyeOuter], faceLm[F.rEyeOuter]), forehead: faceLm[F.forehead], chin: faceLm[F.chin] } : {};
    if (poseLm) { regions.neck = mid(mid(poseLm[P.lSh], poseLm[P.rSh]), faceLm ? faceLm[F.chin] : poseLm[P.nose]); regions.chest = mid(poseLm[P.lSh], poseLm[P.rSh]); regions.ear = poseLm[P.lEar]; }
    const faceSize = faceLm ? dist(faceLm[F.lEyeOuter], faceLm[F.rEyeOuter]) : 0.1;
    let fingerEnergy = 0, cover = 0, q = 0, minFaceDist = 1, contactRegion = "none";
    hands.forEach((lm, i) => {
      const side = (handed[i]?.[0]?.categoryName || "Left").toLowerCase() === "left" ? "right" : "left"; // mirrored camera
      const tip = lm[8], wrist = lm[0]; const prev = this.prev[`hand_${side}`];
      const vel = prev ? dist(wrist, prev[0]) * fps : 0; out[`hand_velocity_${side}`] = vel;
      if (prev) { let fe = 0; for (const j of [4, 8, 12, 16, 20]) fe += dist(lm[j], prev[j]); fingerEnergy += (fe / 5) * fps; }
      this.prev[`hand_${side}`] = lm.map((p) => ({ ...p })); this.handHist[side].push(wrist.y);
      let best = "none", bestD = 1;
      for (const [name, pt] of Object.entries(regions)) { const d = Math.min(dist(tip, pt), dist(lm[12], pt), dist(wrist, pt)) / (faceSize * 2.5 || 1); if (d < bestD) { bestD = d; best = name; } }
      out[`hand_face_distance_${side}`] = bestD; if (bestD < minFaceDist) { minFaceDist = bestD; contactRegion = bestD < 0.6 ? best : "none"; }
      if (faceLm && bestD < 0.5) cover += 0.4;
      const ext = dist(lm[8], wrist) / (dist(lm[12], wrist) + dist(lm[16], wrist) + dist(lm[20], wrist)) * 3; out.pointing_score = Math.max(out.pointing_score, clamp(ext - 0.9, 0, 1));
      out.gesture_event = out.gesture_event || (vel > 0.6 && bestD > 0.8);
      const hz = this.handHist[side].dominantHz(fps); if (hz > out.hand_oscillation_hz) { out.hand_oscillation_hz = hz; out.hand_oscillation_amplitude = Math.max(...this.handHist[side].buf) - Math.min(...this.handHist[side].buf); }
      q += hr.handedness?.[i]?.[0]?.score || 0.5;
    });
    if (hands.length === 2) out.hands_clasped_score = dist(hands[0][0], hands[1][0]) < faceSize * 1.5 ? 1 : 0;
    if (faceLm && minFaceDist < 0.35 && regions.chin && Math.min(...hands.map((h) => dist(h[0], regions.chin))) < faceSize * 1.2) out.head_support_score = 0.7;
    out.hand_region_contact = contactRegion; out.hand_contact_event = contactRegion !== "none" && this.prev.contactRegion !== contactRegion; this.prev.contactRegion = contactRegion;
    out.finger_motion_energy = fingerEnergy / hands.length; out.hand_covering_face_score = clamp(cover, 0, 1);
    return { q: q / hands.length, visible: true, features: out };
  }
}

window.RavanVision = new VisionWorker();
