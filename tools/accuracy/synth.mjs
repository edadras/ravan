/**
 * Synthetic ground truth for the accuracy benchmarks.
 *
 * Real accuracy has two parts. The first is how well MediaPipe places a
 * landmark on a real person, which is the model's property and is quoted from
 * its published evaluation — we cannot re-measure it without a labelled video
 * corpus. The second is how faithfully Ravan turns those landmarks into the
 * numbers the clinician sees. That second part is what these generators test:
 * a signal with a known answer goes in, and the measured answer is compared
 * against it.
 *
 * Every generator takes an explicit RNG seed so a benchmark run is
 * reproducible and a regression is a real regression.
 */

/** Deterministic PRNG (mulberry32) — no dependency, identical across machines. */
export function rng(seed = 1) {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Box-Muller normal deviate. */
export function gauss(rand, sigma = 1) {
  const u = Math.max(rand(), 1e-12);
  return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * rand()) * sigma;
}

/**
 * Frame timestamps for a camera nominally running at `fps`.
 *
 * Browser frame delivery is not uniform: requestAnimationFrame is tied to the
 * display, the tab competes for the GPU with the video call itself, and a
 * landmarker inference occasionally overruns a frame. `jitter` is the standard
 * deviation of the interval as a fraction of the nominal one; `dropRate` is
 * the probability that a frame is skipped entirely.
 */
export function frameTimes(seconds, fps, { jitter = 0.25, dropRate = 0.05, seed = 7 } = {}) {
  const rand = rng(seed);
  const out = [];
  let t = 0;
  const nominal = 1000 / fps;
  while (t < seconds * 1000) {
    out.push(t);
    let step = nominal * (1 + gauss(rand, jitter));
    if (rand() < dropRate) step += nominal;
    t += Math.max(nominal * 0.35, step);
  }
  return out;
}

/**
 * Eye-aspect-ratio trace with known blinks.
 *
 * Models the three things that broke the previous detector: a slowly drifting
 * open-eye baseline (the patient leans in over a session, so the eye subtends
 * a different angle), measurement noise, and bursts of rapid blinking.
 */
export function earTrace(times, {
  baseline = 0.30, drift = 0.05, noise = 0.012, blinkEveryMs = 3800,
  blinkMs = 140, burstAt = [], seed = 11,
} = {}) {
  const rand = rng(seed);
  const total = times[times.length - 1];
  const blinks = [];
  for (let t = blinkEveryMs; t < total; t += blinkEveryMs * (0.7 + 0.6 * rand())) {
    blinks.push({ start: t, duration: blinkMs * (0.7 + 0.6 * rand()) });
  }
  // Bursts: four blinks 220 ms apart, the pattern that dragged a running-mean
  // threshold below the closed-eye value and hid every blink after the first.
  for (const at of burstAt) {
    for (let k = 0; k < 4; k++) blinks.push({ start: at + k * 220, duration: 120 });
  }
  blinks.sort((a, b) => a.start - b.start);

  const values = times.map((t) => {
    const open = baseline * (1 + drift * (t / total));
    let v = open;
    for (const b of blinks) {
      if (t >= b.start && t <= b.start + b.duration) {
        // Lid closure is roughly a raised-cosine dip, not a square wave.
        const phase = (t - b.start) / b.duration;
        v = open * (1 - 0.78 * (0.5 - 0.5 * Math.cos(2 * Math.PI * phase)));
      }
    }
    return v + gauss(rand, noise);
  });
  return { values, blinks };
}

/**
 * An oscillating signal (leg bounce, hand tremor, rocking) at a known
 * frequency, on top of a postural drift and broadband noise.
 */
export function oscillation(times, { hz = 4.5, amplitude = 0.01, driftPerS = 0.004, noise = 0.0025, seed = 13 } = {}) {
  const rand = rng(seed);
  return times.map((t) => {
    const s = t / 1000;
    return amplitude * Math.sin(2 * Math.PI * hz * s) + driftPerS * s + gauss(rand, noise);
  });
}

/** The same shape with no periodic component — used to count false positives. */
export function nonOscillation(times, { driftPerS = 0.004, noise = 0.0035, seed = 17 } = {}) {
  const rand = rng(seed);
  let walk = 0;
  return times.map((t) => {
    walk += gauss(rand, noise);
    return walk + driftPerS * (t / 1000);
  });
}

const P = { nose: 0, lEar: 7, rEar: 8, lSh: 11, rSh: 12, lHip: 23, rHip: 24 };

/**
 * A seated upper body leaning forward by `leanDeg`, rendered both as metric
 * world landmarks (metres, hip-centred, as MediaPipe's worldLandmarks) and as
 * normalised image landmarks under a pinhole camera at `distanceM`.
 *
 * The distance sweep is the point: a correct lean measurement must give the
 * same angle whether the patient sits 60 cm or 150 cm from the camera.
 */
export function seatedTorso({ leanDeg = 12, distanceM = 0.9, shoulderWidthM = 0.40, torsoHeightM = 0.52, focal = 1.2, seed = 19, noiseM = 0.004 } = {}) {
  const rand = rng(seed);
  const a = (leanDeg * Math.PI) / 180;
  const half = shoulderWidthM / 2;

  // Hips at the origin; shoulders raised by the torso height and rotated
  // forward (towards the camera, i.e. -z) by the lean angle.
  const shY = torsoHeightM * Math.cos(a);
  const shZ = -torsoHeightM * Math.sin(a);

  const world = [];
  const put = (i, x, y, z) => {
    world[i] = { x: x + gauss(rand, noiseM), y: y + gauss(rand, noiseM), z: z + gauss(rand, noiseM), visibility: 0.95 };
  };
  put(P.lHip, -half * 0.55, 0, 0);
  put(P.rHip, half * 0.55, 0, 0);
  put(P.lSh, -half, shY, shZ);
  put(P.rSh, half, shY, shZ);
  put(P.lEar, -half * 0.28, shY + 0.22 * Math.cos(a), shZ - 0.22 * Math.sin(a));
  put(P.rEar, half * 0.28, shY + 0.22 * Math.cos(a), shZ - 0.22 * Math.sin(a));
  put(P.nose, 0, shY + 0.24 * Math.cos(a), shZ - 0.24 * Math.sin(a) - 0.09);

  // Pinhole projection into a normalised image, with the sitter centred.
  const landmarks = [];
  for (const i of Object.values(P)) {
    const w = world[i];
    if (!w) continue;
    const depth = distanceM - w.z;
    landmarks[i] = {
      x: 0.5 + (focal * w.x) / depth,
      y: 0.5 - (focal * (w.y - torsoHeightM * 0.5)) / depth,
      // MediaPipe's normalised z is roughly "depth relative to the hips, in the
      // same units as x" — it carries no metric scale, which is the flaw the
      // world landmarks avoid.
      z: (-w.z * focal) / distanceM,
      visibility: 0.95,
    };
  }
  return { world, landmarks, leanDeg, distanceM, P };
}

/** A landmark held still, then moved at a constant speed — for filter lag. */
export function stepThenRamp(times, { stepAt = 1500, rampFrom = 3000, speed = 0.25, noise = 0.004, seed = 23 } = {}) {
  const rand = rng(seed);
  return times.map((t) => {
    let v = 0;
    if (t >= stepAt) v += 0.1;
    if (t >= rampFrom) v += (speed * (t - rampFrom)) / 1000;
    return { clean: v, noisy: v + gauss(rand, noise) };
  });
}

export { P as POSE_INDICES };

/**
 * An EAR trace with sustained eye closures rather than blinks.
 *
 * Closing the eyes for seconds at a time is a catalog signal in its own right.
 * It is also where a threshold taken from a short running mean fails outright:
 * once the mean itself has fallen to the closed value, the threshold follows it
 * down and the eye is declared open again while it is still shut.
 */
export function sustainedClosureTrace(times, { baseline = 0.30, noise = 0.010, closures = [[20000, 3000], [60000, 2200], [95000, 4100]], seed = 29 } = {}) {
  const rand = rng(seed);
  const values = times.map((t) => {
    let v = baseline;
    for (const [start, duration] of closures) {
      if (t >= start && t <= start + duration) {
        // 120 ms to close, 120 ms to open, shut in between.
        const into = t - start;
        const ramp = Math.min(1, into / 120, (duration - into) / 120);
        v = baseline * (1 - 0.8 * Math.max(0, ramp));
      }
    }
    return v + gauss(rand, noise);
  });
  return { values, closures };
}
