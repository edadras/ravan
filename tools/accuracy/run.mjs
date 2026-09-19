#!/usr/bin/env node
/**
 * Ravan feature-extraction accuracy benchmark.
 *
 *   node tools/accuracy/run.mjs            human-readable report
 *   node tools/accuracy/run.mjs --json     machine-readable, for CI
 *   node tools/accuracy/run.mjs --check    exit non-zero if a budget is missed
 *
 * Each benchmark feeds a signal whose answer is known into the shipping code
 * and reports the error, beside the same measurement from the implementation
 * this branch replaced. The budgets are regression guards: they are set a
 * little looser than the current result, so ordinary noise does not fail a
 * build but a real degradation does.
 *
 * What this does NOT measure: how accurately MediaPipe places a landmark on a
 * real face or body. That is the model's own property; see docs/12-accuracy.md
 * for the published figures and for what they do and do not license us to say.
 */

import { BlinkDetector, headPoseFromMatrix, matrixFromHeadPose, torsoGeometry } from '../../flutter_app/web/ravan/geometry.mjs';
import { OneEuro, dominantFrequency } from '../../flutter_app/web/ravan/filters.mjs';
import { LegacyBlink, legacyDominantHz, legacyHeadPose, legacyTorsoAngle } from './legacy.mjs';
import { earTrace, frameTimes, nonOscillation, oscillation, seatedTorso, stepThenRamp, sustainedClosureTrace } from './synth.mjs';

const results = [];
const record = (r) => { results.push(r); return r; };
const fmt = (v, d = 3) => (Number.isFinite(v) ? v.toFixed(d) : 'n/a');

// ---------------------------------------------------------------- head pose
function benchHeadPose() {
  let worstNew = 0, worstOld = 0;
  const samples = [];
  for (let yaw = -50; yaw <= 50; yaw += 5) {
    for (let pitch = -35; pitch <= 35; pitch += 5) {
      for (let roll = -25; roll <= 25; roll += 5) {
        const M = matrixFromHeadPose(yaw, pitch, roll);
        const n = headPoseFromMatrix(M);
        const o = legacyHeadPose(M);
        const eNew = Math.max(Math.abs(n.yaw - yaw), Math.abs(n.pitch - pitch), Math.abs(n.roll - roll));
        const eOld = Math.max(Math.abs(o.yaw - yaw), Math.abs(o.pitch - pitch), Math.abs(o.roll - roll));
        worstNew = Math.max(worstNew, eNew);
        worstOld = Math.max(worstOld, eOld);
        samples.push(eOld);
      }
    }
  }
  samples.sort((a, b) => a - b);
  return record({
    name: 'head pose: yaw/pitch/roll recovered from the transformation matrix',
    unit: 'degrees, worst case over yaw ±50°, pitch ±35°, roll ±25°',
    value: worstNew,
    previous: worstOld,
    extra: { previous_median_error_deg: samples[samples.length >> 1] },
    budget: 0.001,
    better: 'lower',
  });
}

// ------------------------------------------------------------------- blinks
function benchBlink() {
  // The face landmarker runs at 30 fps in the browser (FACE_FPS in
  // vision_worker.js), not at the 15 fps the pose and hand models use. A
  // 140 ms blink spans about two frames at 15 fps and four at 30, and the
  // difference is worth roughly 14 points of recall; that is why the face
  // model was given its own, faster loop.
  const FACE_FPS = 30;
  const times = frameTimes(180, FACE_FPS, { seed: 3 });
  const { values, blinks } = earTrace(times, { burstAt: [40000, 95000, 140000], seed: 5 });

  const score = (detections) => {
    const matched = new Set();
    let tp = 0, fp = 0;
    const durationErrors = [];
    for (const d of detections) {
      // A detection belongs to the ground-truth blink whose window contains it.
      const i = blinks.findIndex((b, k) => !matched.has(k) && d.t >= b.start - 120 && d.t <= b.start + b.duration + 220);
      if (i >= 0) { matched.add(i); tp++; durationErrors.push(Math.abs(d.durationMs - blinks[i].duration)); }
      else fp++;
    }
    const recall = tp / blinks.length;
    const precision = tp + fp > 0 ? tp / (tp + fp) : 0;
    durationErrors.sort((a, b) => a - b);
    return {
      recall, precision,
      f1: precision + recall > 0 ? (2 * precision * recall) / (precision + recall) : 0,
      medianDurationErrorMs: durationErrors.length ? durationErrors[durationErrors.length >> 1] : NaN,
    };
  };

  const det = new BlinkDetector();
  const newDet = [];
  times.forEach((t, i) => {
    const r = det.update(values[i], t);
    if (r.blink) newDet.push({ t, durationMs: r.durationMs });
  });

  const legacy = new LegacyBlink();
  const oldDet = [];
  times.forEach((t, i) => {
    const r = legacy.update(values[i], t);
    if (r.blink) oldDet.push({ t, durationMs: r.durationMs });
  });

  const n = score(newDet), o = score(oldDet);
  record({
    name: 'blink detection: F1 over a 3-minute trace with drift, noise and rapid bursts',
    unit: `F1 against ${blinks.length} ground-truth blinks`,
    value: n.f1,
    previous: o.f1,
    extra: {
      recall: +n.recall.toFixed(3), precision: +n.precision.toFixed(3),
      previous_recall: +o.recall.toFixed(3), previous_precision: +o.precision.toFixed(3),
    },
    budget: 0.90,
    better: 'higher',
  });
  record({
    name: 'blink duration error',
    unit: 'milliseconds, median',
    value: n.medianDurationErrorMs,
    previous: o.medianDurationErrorMs,
    budget: 70,
    better: 'lower',
  });
  return benchSustainedClosure(FACE_FPS);
}

/**
 * Eyes closed for seconds at a time is its own catalog signal, and it is where
 * a threshold taken from a short running mean breaks down: the mean falls to
 * the closed value and the eye is declared open again while it is still shut.
 */
function benchSustainedClosure(fps) {
  const times = frameTimes(120, fps, { seed: 13 });
  const { values, closures } = sustainedClosureTrace(times);

  const det = new BlinkDetector();
  const peak = new Map();
  let spuriousBlinks = 0;
  times.forEach((t, i) => {
    const r = det.update(values[i], t);
    const c = closures.find(([start, d]) => t >= start && t <= start + d);
    if (r.closureS > 0 && c) peak.set(c[0], Math.max(peak.get(c[0]) ?? 0, r.closureS * 1000));
    if (r.blink && c) spuriousBlinks++;
  });

  const legacy = new LegacyBlink();
  let legacySpurious = 0;
  times.forEach((t, i) => {
    const r = legacy.update(values[i], t);
    if (r.blink && closures.some(([start, d]) => t >= start && t <= start + d + 200)) legacySpurious++;
  });

  const errors = closures.map(([start, d]) => Math.abs((peak.get(start) ?? 0) - d));
  const worst = Math.max(...errors);
  return record({
    name: 'sustained eye closure: measured duration of closures lasting 2-4 seconds',
    unit: 'milliseconds, worst error over three closures',
    value: worst,
    previous: NaN,
    extra: {
      closures_found: `${[...peak.keys()].length}/${closures.length}`,
      blinks_wrongly_reported_during_a_closure: spuriousBlinks,
      previous_blinks_wrongly_reported: legacySpurious,
      note: 'the previous detector reported no closure duration at all, only blinks',
    },
    budget: 250,
    better: 'lower',
  });
}

// ------------------------------------------------------ oscillation frequency
function benchOscillation() {
  // Two bands, because what is observable depends on the stream's frame rate:
  // the pose/hand stream runs at 15 fps, so nothing above ~6.7 Hz is real, and
  // the face stream at 30 fps reaches the whole tremor range.
  const cases = [
    { fps: 15, fMax: 6, hzs: [1.0, 2.0, 3.0, 4.0, 5.0, 6.0], label: '15 fps stream, 0.5-6 Hz' },
    { fps: 30, fMax: 12, hzs: [1.0, 3.0, 5.0, 7.0, 9.0, 11.0], label: '30 fps stream, 0.5-12 Hz' },
  ];
  const errors = [], legacyErrors = [];
  let reported = 0, trials = 0;

  for (const c of cases) {
    for (const hz of c.hzs) {
      for (const seed of [31, 37, 41]) {
        const times = frameTimes(8, c.fps, { seed });
        const values = oscillation(times, { hz, seed });
        const secs = times.map((t) => t / 1000);
        trials++;
        const n = dominantFrequency(secs, values, { fMin: 0.5, fMax: c.fMax, minSeconds: 3 });
        if (n) { reported++; errors.push(Math.abs(n.hz - hz)); }
        // The previous estimator assumed a uniform sample rate and used the
        // nominal one, with no window, no detrend and no Nyquist guard.
        const o = legacyDominantHz(values, c.fps);
        if (o > 0) legacyErrors.push(Math.abs(o - hz));
      }
    }
  }
  const mean = (a) => (a.length ? a.reduce((x, y) => x + y, 0) / a.length : NaN);

  record({
    name: 'oscillation frequency: error on a known sinusoid, irregular frames',
    unit: 'Hz, mean absolute error within each stream\'s observable band',
    value: mean(errors),
    previous: mean(legacyErrors),
    extra: {
      reported_rate: `${reported}/${trials}`,
      previous_reported_rate: `${legacyErrors.length}/${trials}`,
      bands: cases.map((c) => c.label).join(' | '),
    },
    budget: 0.25,
    better: 'lower',
  });

  // False positives: a drifting random walk must not be called a tremor.
  let fpNew = 0, fpOld = 0, fpTrials = 0;
  for (const c of cases) {
    for (let seed = 50; seed < 80; seed++) {
      const times = frameTimes(8, c.fps, { seed });
      const values = nonOscillation(times, { seed });
      const secs = times.map((t) => t / 1000);
      if (dominantFrequency(secs, values, { fMin: 0.5, fMax: c.fMax, minSeconds: 3 })) fpNew++;
      if (legacyDominantHz(values, c.fps) > 0) fpOld++;
      fpTrials++;
    }
  }
  return record({
    name: 'oscillation false positives: a drifting random walk reported as a rhythm',
    unit: `fraction of ${fpTrials} non-oscillating traces`,
    value: fpNew / fpTrials,
    previous: fpOld / fpTrials,
    budget: 0.10,
    better: 'lower',
  });
}

// --------------------------------------------------------------- torso angle
function benchTorso() {
  const errNew = [], errOld = [];
  const spreadNew = {}, spreadOld = {};
  for (const lean of [0, 5, 10, 15, 20, 25]) {
    const perDistanceNew = [], perDistanceOld = [];
    for (const distanceM of [0.6, 0.8, 1.0, 1.25, 1.5]) {
      const s = seatedTorso({ leanDeg: lean, distanceM, seed: 100 + Math.round(lean * 7 + distanceM * 10) });
      const g = torsoGeometry(s.world, s.P);
      const measured = g.lean;
      const legacy = legacyTorsoAngle(s.landmarks, s.P);
      errNew.push(Math.abs(measured - lean));
      errOld.push(Math.abs(legacy - lean));
      perDistanceNew.push(measured);
      perDistanceOld.push(legacy);
    }
    // How much the *same* posture appears to change when the patient simply
    // moves closer to the camera. A per-person baseline cannot subtract this.
    const range = (a) => Math.max(...a) - Math.min(...a);
    spreadNew[lean] = range(perDistanceNew);
    spreadOld[lean] = range(perDistanceOld);
  }
  const mean = (a) => a.reduce((x, y) => x + y, 0) / a.length;
  record({
    name: 'torso lean: error against a known seated posture',
    unit: 'degrees, mean absolute error over 0-25° of lean',
    value: mean(errNew),
    previous: mean(errOld),
    budget: 2.0,
    better: 'lower',
  });
  return record({
    name: 'torso lean: apparent change caused by the patient moving 0.6-1.5 m from the camera',
    unit: 'degrees, worst case; ideally zero',
    value: Math.max(...Object.values(spreadNew)),
    previous: Math.max(...Object.values(spreadOld)),
    budget: 2.0,
    better: 'lower',
  });
}

// ------------------------------------------------------------------ velocity
function benchVelocity() {
  // A landmark moving at a known constant speed, sampled on a jittery clock.
  const times = frameTimes(20, 15, { jitter: 0.3, dropRate: 0.08, seed: 91 });
  const speed = 0.4; // units per second
  const pos = times.map((t) => (speed * t) / 1000);

  let errReal = 0, errNominal = 0, n = 0;
  for (let i = 1; i < times.length; i++) {
    const dt = (times[i] - times[i - 1]) / 1000;
    if (dt <= 0 || dt > 0.4) continue;
    const d = pos[i] - pos[i - 1];
    errReal += Math.abs(d / dt - speed);
    errNominal += Math.abs(d * 15 - speed); // the previous code multiplied by nominal fps
    n++;
  }
  return record({
    name: 'velocity: error from timing frames by the real interval rather than the nominal frame rate',
    unit: 'units/s, mean absolute error at a true 0.4 units/s',
    value: errReal / n,
    previous: errNominal / n,
    budget: 1e-9,
    better: 'lower',
  });
}

// -------------------------------------------------------------- filter noise
function benchFilter() {
  const times = frameTimes(8, 15, { seed: 97 });
  const trace = stepThenRamp(times);
  // The constants FaceExtractor actually ships (FACE_FILTER in features.mjs).
  const f = new OneEuro({ minCutoff: 1.0, beta: 10, dCutoff: 1.0 });
  let rawNoise = 0, filtNoise = 0, n = 0;
  let worstLag = 0;
  for (let i = 1; i < times.length; i++) {
    const dt = (times[i] - times[i - 1]) / 1000;
    const out = f.filter(trace[i].noisy, dt);
    const t = times[i];
    // Steady stretches: measure how much noise survives.
    if (t > 300 && t < 1400) { rawNoise += Math.abs(trace[i].noisy - trace[i].clean); filtNoise += Math.abs(out - trace[i].clean); n++; }
    // Ramp: measure the tracking error the smoothing costs.
    if (t > 3600) worstLag = Math.max(worstLag, Math.abs(out - trace[i].clean));
  }
  record({
    name: 'landmark smoothing: residual noise while the subject is still',
    unit: 'fraction of the unfiltered noise that survives',
    value: filtNoise / rawNoise,
    previous: 1,
    extra: { note: 'the previous version applied no temporal filter at all' },
    budget: 0.55,
    better: 'lower',
  });
  return record({
    name: 'landmark smoothing: tracking error during steady movement (the cost of the filter)',
    unit: 'normalised units, worst case at 0.25 units/s',
    value: worstLag,
    previous: 0,
    extra: { note: 'unfiltered input has no lag but ~2x the jitter, see the row above' },
    budget: 0.018,
    better: 'lower',
  });
}

// ---------------------------------------------------------------------- main
const benches = [benchHeadPose, benchBlink, benchOscillation, benchTorso, benchVelocity, benchFilter];
for (const b of benches) b();

const failures = results.filter((r) => (r.better === 'lower' ? r.value > r.budget : r.value < r.budget));

if (process.argv.includes('--json')) {
  console.log(JSON.stringify({ generated: new Date().toISOString(), results, failures: failures.length }, null, 2));
} else {
  const line = '─'.repeat(78);
  console.log(`\nRavan feature-extraction accuracy\n${line}`);
  for (const r of results) {
    const ok = r.better === 'lower' ? r.value <= r.budget : r.value >= r.budget;
    console.log(`\n${ok ? '✓' : '✗'} ${r.name}`);
    console.log(`    ${r.unit}`);
    console.log(`    measured  ${fmt(r.value, r.value < 1 ? 4 : 2)}`);
    console.log(`    previous  ${fmt(r.previous, r.previous < 1 ? 4 : 2)}`);
    console.log(`    budget    ${r.better === 'lower' ? '≤' : '≥'} ${r.budget}`);
    for (const [k, v] of Object.entries(r.extra || {})) console.log(`    ${k}: ${v}`);
  }
  console.log(`\n${line}`);
  console.log(failures.length ? `${failures.length} benchmark(s) outside budget` : 'all benchmarks within budget');
  console.log();
}

if (process.argv.includes('--check') && failures.length) process.exit(1);
