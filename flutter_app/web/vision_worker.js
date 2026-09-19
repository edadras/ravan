/**
 * Ravan on-device vision feature extractor.
 *
 * Runs the MediaPipe face, pose and hand landmarkers on the *local* camera
 * stream and turns landmarks into the derived features listed in
 * catalog/feature_dictionary.json. Only those numbers are sent to the analysis
 * service. No pixels leave the browser except through the encrypted WebRTC
 * call itself.
 *
 * This file is deliberately thin: it owns the camera, the models, the frame
 * loop and the socket. Every number is computed in ./ravan/, which has no DOM
 * and no MediaPipe dependency so tools/accuracy can run the same code against
 * ground truth under Node. If you change a measurement, change it there.
 *
 * Public API (window.RavanVision):
 *   start({ videoEl, wsUrl, token, onStatus })  -> Promise<void>
 *   setSpeakerState('patient_speaking' | 'patient_listening' | 'silence')
 *   pause() / resume() / stop()
 */

/* ---------------------------------------------------------------- assets ---
 * A deployed server hosts the MediaPipe runtime and models itself (see
 * deploy/fetch-vendor.sh); the CDN is only a fallback for a developer running
 * `flutter run` without having fetched them.
 */
const LOCAL = new URL('vendor/', document.baseURI).href;
const CDN = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.22-rc.20250304/';
const MODEL_CDN = 'https://storage.googleapis.com/mediapipe-models/';

/** The face model is cheap and blink timing needs the frames; pose and hands
 *  are heavier and their signals are much slower, so they run at half rate.
 *  Dropping the face stream to 15 fps costs about 14 points of blink recall —
 *  see tools/accuracy/run.mjs. */
const FACE_FPS = 30;
const BODY_FPS = 15;

/** If a pose inference takes longer than this share of its frame budget for a
 *  sustained stretch, the heavy model is swapped for the full one. Better an
 *  honest 15 fps from a slightly less precise model than a stuttering stream
 *  from a precise one: intermittent frames corrupt every velocity and every
 *  frequency the analysis service derives. */
const POSE_BUDGET_RATIO = 0.8;
const POSE_DOWNGRADE_AFTER = 45;

let tasks = null;
async function loadTasks() {
  if (tasks) return tasks;
  try {
    tasks = await import(/* @vite-ignore */ `${LOCAL}vision_bundle.mjs`);
    tasks.__wasmRoot = `${LOCAL}wasm`;
    tasks.__modelRoot = `${LOCAL}models/`;
    tasks.__source = 'local';
  } catch (_) {
    console.warn('[ravan] vendored MediaPipe not found; falling back to the public CDN. ' +
      'Run deploy/fetch-vendor.sh so a deployed server does not depend on it.');
    tasks = await import(/* @vite-ignore */ `${CDN}vision_bundle.mjs`);
    tasks.__wasmRoot = `${CDN}wasm`;
    tasks.__modelRoot = null;
    tasks.__source = 'cdn';
  }
  return tasks;
}

const CDN_MODELS = {
  face: `${MODEL_CDN}face_landmarker/face_landmarker/float16/1/face_landmarker.task`,
  poseHeavy: `${MODEL_CDN}pose_landmarker/pose_landmarker_heavy/float16/1/pose_landmarker_heavy.task`,
  poseFull: `${MODEL_CDN}pose_landmarker/pose_landmarker_full/float16/1/pose_landmarker_full.task`,
  hand: `${MODEL_CDN}hand_landmarker/hand_landmarker/float16/1/hand_landmarker.task`,
};
const LOCAL_MODELS = {
  face: 'face_landmarker.task',
  poseHeavy: 'pose_landmarker_heavy.task',
  poseFull: 'pose_landmarker_full.task',
  hand: 'hand_landmarker.task',
};
const modelUrl = (key) => (tasks.__modelRoot ? tasks.__modelRoot + LOCAL_MODELS[key] : CDN_MODELS[key]);

/* ------------------------------------------------------------------------ */

class VisionWorker {
  constructor() {
    this.running = false;
    this.paused = false;
    this.speakerState = 'patient_listening';
    this.t0 = 0;
  }

  async start({ videoEl, wsUrl, token, onStatus = () => {} }) {
    this.video = videoEl;
    this.onStatus = onStatus;

    const { FaceLandmarker, PoseLandmarker, HandLandmarker, FilesetResolver } = await loadTasks();
    const vision = await FilesetResolver.forVisionTasks(tasks.__wasmRoot);
    this.mp = { FaceLandmarker, PoseLandmarker, HandLandmarker, vision };

    this.face = await FaceLandmarker.createFromOptions(vision, {
      baseOptions: { modelAssetPath: modelUrl('face'), delegate: 'GPU' },
      runningMode: 'VIDEO',
      numFaces: 1,
      outputFaceBlendshapes: true,
      outputFacialTransformationMatrixes: true,
      // The landmarker's own smoothing is left off: Ravan filters landmarks
      // itself with parameters tuned against the accuracy benchmark, and
      // stacking two filters adds lag without removing more noise.
      minFaceDetectionConfidence: 0.5,
      minFacePresenceConfidence: 0.5,
      minTrackingConfidence: 0.5,
    });
    this.poseVariant = 'heavy';
    this.pose = await this._createPose('poseHeavy');
    this.hand = await HandLandmarker.createFromOptions(vision, {
      baseOptions: { modelAssetPath: modelUrl('hand'), delegate: 'GPU' },
      runningMode: 'VIDEO',
      numHands: 2,
      minHandDetectionConfidence: 0.5,
      minTrackingConfidence: 0.5,
    });

    const { FaceExtractor, PoseExtractor, HandExtractor, frameQuality } = await import(
      /* @vite-ignore */ new URL('ravan/features.mjs', document.baseURI).href
    );
    this.frameQuality = frameQuality;
    this.faceX = new FaceExtractor();
    this.poseX = new PoseExtractor();
    this.handX = new HandExtractor();

    // 96x96 greyscale thumbnail: enough resolution for the Laplacian blur
    // estimate, small enough to read back every frame without stalling the GPU.
    this.canvas = document.createElement('canvas');
    this.canvas.width = 96;
    this.canvas.height = 96;
    this.ctx = this.canvas.getContext('2d', { willReadFrequently: true });
    this.gray = new Uint8ClampedArray(96 * 96);
    this.lastHash = null;
    this.frozenFrames = 0;

    this.ws = new WebSocket(`${wsUrl}?token=${encodeURIComponent(token)}`);
    this.ws.onopen = () => onStatus({ state: 'connected' });
    this.ws.onclose = () => onStatus({ state: 'disconnected' });
    this.ws.onerror = () => onStatus({ state: 'error' });
    this.ws.onmessage = (m) => {
      try {
        const d = JSON.parse(m.data);
        if (d.type === 'events') onStatus({ state: 'events', events: d.events });
      } catch (_) { /* a malformed frame from the server is not worth a crash */ }
    };

    // Kept for the audio worker, which shares this origin when the page could
    // not reach the server's clock.
    this.t0 = performance.now();
    this.last = { face: 0, body: 0 };
    this.poseOverruns = 0;
    this.running = true;
    this.paused = false;
    onStatus({ state: 'started', models: tasks.__source, pose: this.poseVariant });
    this._loop();
  }

  async _createPose(key) {
    return this.mp.PoseLandmarker.createFromOptions(this.mp.vision, {
      baseOptions: { modelAssetPath: modelUrl(key), delegate: 'GPU' },
      runningMode: 'VIDEO',
      numPoses: 1,
      minPoseDetectionConfidence: 0.5,
      minPosePresenceConfidence: 0.5,
      minTrackingConfidence: 0.5,
      // World landmarks are what every postural angle is computed from; the
      // normalised ones carry no metric scale.
      outputSegmentationMasks: false,
    });
  }

  setSpeakerState(s) { this.speakerState = s; }

  pause() { this.paused = true; this.onStatus({ state: 'paused' }); }

  resume() {
    this.paused = false;
    // A pause means a gap, and a gap is not a velocity: forget the previous
    // frame so nothing is differenced across it.
    this.faceX.prev = null;
    this.poseX.prev = null;
    this.poseX.prevWorld = null;
    this.handX.prev = {};
    this.onStatus({ state: 'resumed' });
  }

  stop() {
    this.running = false;
    try { this.ws?.close(); } catch (_) { /* already gone */ }
    for (const m of [this.face, this.pose, this.hand]) { try { m?.close(); } catch (_) { /* ditto */ } }
  }

  _loop() {
    const tick = () => {
      if (!this.running) return;
      requestAnimationFrame(tick);
      if (this.paused || !this.video || this.video.readyState < 2) return;
      const now = performance.now();
      const doFace = now - this.last.face >= 1000 / FACE_FPS - 1;
      const doBody = now - this.last.body >= 1000 / BODY_FPS - 1;
      if (!doFace && !doBody) return;
      try {
        this._process(now, doFace, doBody);
      } catch (e) {
        console.warn('[ravan] frame failed', e);
      }
    };
    requestAnimationFrame(tick);
  }

  /** Session time on the origin every recorder shares (see ravan_clock.js). */
  _tMs() {
    return window.RavanClock?.synced ? window.RavanClock.nowMs() : Math.round(performance.now() - this.t0);
  }

  _send(source, features, quality) {
    if (this.ws?.readyState !== 1) return;
    this.ws.send(JSON.stringify({
      type: 'frame',
      data: {
        t_ms: this._tMs(),
        source,
        features,
        quality,
        speaker_state: this.speakerState,
      },
    }));
  }

  _process(now, doFace, doBody) {
    const tMs = this._tMs();
    const v = this.video;

    // ---- frame quality from a greyscale thumbnail
    this.ctx.drawImage(v, 0, 0, 96, 96);
    const px = this.ctx.getImageData(0, 0, 96, 96).data;
    for (let i = 0, j = 0; i < px.length; i += 4, j++) {
      // Rec. 601 luma; the landmarkers see colour, this is only for the gates.
      this.gray[j] = (px[i] * 299 + px[i + 1] * 587 + px[i + 2] * 114) / 1000;
    }
    const fq = this.frameQuality(this.gray, 96, 96, { previousHash: this.lastHash });
    this.lastHash = fq.hash;
    this.frozenFrames = fq.frozen ? this.frozenFrames + 1 : 0;

    if (doFace) {
      const dt = this.last.face ? (now - this.last.face) / 1000 : 1 / FACE_FPS;
      this.last.face = now;
      const res = this.face.detectForVideo(v, now);
      const faces = res.faceLandmarks?.length ?? 0;
      const shared = {
        illumination: fq.illumination,
        illumination_uniformity: fq.illumination_uniformity,
        motion_blur: fq.motion_blur,
        fps: this.frozenFrames > FACE_FPS * 2 ? 0 : Math.round(1 / Math.max(dt, 1e-3)),
        faces_detected: faces,
      };
      if (faces) {
        const blendshapes = {};
        for (const c of res.faceBlendshapes?.[0]?.categories ?? []) blendshapes[c.categoryName] = c.score;
        const out = this.faceX.update({
          landmarks: res.faceLandmarks[0],
          blendshapes,
          matrix: res.facialTransformationMatrixes?.[0]?.data,
          tMs,
          dt,
          videoWidth: v.videoWidth || 640,
        });
        if (out) {
          const osc = this.faceX.headOscillation();
          if (osc) {
            out.features.head_oscillation_hz = osc.hz;
            out.features.head_oscillation_prominence = osc.prominence;
          }
          this._send('face', out.features, { ...out.quality, ...shared });
          this.lastFace = res.faceLandmarks[0];
        }
      } else {
        this.lastFace = null;
        this._send('quality', {}, { ...shared, face_quality: 0, face_in_frame_ratio: 0 });
      }
    }

    if (doBody) {
      const dt = this.last.body ? (now - this.last.body) / 1000 : 1 / BODY_FPS;
      this.last.body = now;

      const poseStart = performance.now();
      const pr = this.pose.detectForVideo(v, now);
      this._watchPoseCost(performance.now() - poseStart);

      const persons = pr.landmarks?.length ?? 0;
      if (persons) {
        const out = this.poseX.update({
          landmarks: pr.landmarks[0],
          worldLandmarks: pr.worldLandmarks?.[0],
          tMs,
          dt,
        });
        if (out) {
          this._send('pose', out.features, {
            ...out.quality,
            persons_detected: persons,
            pose_model: this.poseVariant,
            metric_landmarks: Boolean(pr.worldLandmarks?.[0]),
            fps: Math.round(1 / Math.max(dt, 1e-3)),
          });
        }
        this.lastPose = pr.landmarks[0];
      } else {
        this.lastPose = null;
        this._send('quality', {}, { pose_quality: 0, persons_detected: 0 });
      }

      const hr = this.hand.detectForVideo(v, now);
      const hands = (hr.landmarks ?? []).map((landmarks, i) => ({
        landmarks,
        // The camera preview is mirrored, so MediaPipe's "Left" is the
        // patient's right hand and vice versa.
        side: (hr.handedness?.[i]?.[0]?.categoryName ?? 'Left').toLowerCase() === 'left' ? 'right' : 'left',
        score: hr.handedness?.[i]?.[0]?.score ?? 0.5,
      }));
      const out = this.handX.update({ hands, faceLm: this.lastFace, poseLm: this.lastPose, tMs, dt });
      this._send('hands', out.features, out.quality);
    }
  }

  /**
   * Swap the heavy pose model for the full one when the machine cannot keep up.
   * Announced through onStatus so the clinician's console can say which model
   * produced a session's numbers, and recorded in every pose quality payload.
   */
  _watchPoseCost(ms) {
    if (this.poseVariant !== 'heavy') return;
    const budget = (1000 / BODY_FPS) * POSE_BUDGET_RATIO;
    this.poseOverruns = ms > budget ? this.poseOverruns + 1 : Math.max(0, this.poseOverruns - 1);
    if (this.poseOverruns < POSE_DOWNGRADE_AFTER) return;
    this.poseOverruns = 0;
    this.poseVariant = 'full';
    this._createPose('poseFull').then((p) => {
      const old = this.pose;
      this.pose = p;
      try { old.close(); } catch (_) { /* nothing to do */ }
      console.info('[ravan] pose model downgraded heavy -> full to hold the frame rate');
      this.onStatus({ state: 'pose_model_changed', pose: 'full' });
    }).catch((e) => {
      this.poseVariant = 'heavy';
      console.warn('[ravan] pose downgrade failed', e);
    });
  }
}

window.RavanVision = new VisionWorker();
