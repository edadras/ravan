/**
 * Ravan on-device audio feature extractor (prosody only, no recording).
 *
 * Computes RMS loudness, voice activity, pitch (autocorrelation), pause statistics and a
 * coarse speaker state from the LOCAL microphone track. Sends features every 500 ms via
 * the same WebSocket used by the vision worker. Transcription (ASR) is a separate,
 * consent-gated component and is not performed here.
 *
 * window.RavanAudio.start({ stream, ws, fs }) / setRemoteSpeaking(bool) / stop()
 */
(function () {
  class AudioWorker {
    constructor() { this.running = false; this.remoteSpeaking = false; }

    start({ stream, ws, t0 }) {
      this.ws = ws; this.t0 = t0 || performance.now();
      this.ctx = new (window.AudioContext || window.webkitAudioContext)();
      const src = this.ctx.createMediaStreamSource(stream);
      this.an = this.ctx.createAnalyser(); this.an.fftSize = 2048; src.connect(this.an);
      this.buf = new Float32Array(this.an.fftSize);
      this.running = true; this.speaking = false; this.speechStart = 0; this.pauseStart = 0; this.pauses = []; this.f0s = []; this.rms = []; this.voiced = 0; this.frames = 0; this.clipped = 0;
      this.timer = setInterval(() => this.tick(), 50);
      this.reporter = setInterval(() => this.report(), 500);
    }

    setRemoteSpeaking(v) { this.remoteSpeaking = v; }
    stop() { this.running = false; clearInterval(this.timer); clearInterval(this.reporter); try { this.ctx?.close(); } catch (_) { } }

    pitch(buf, fs) { // normalized autocorrelation, 70–400 Hz
      const minLag = Math.floor(fs / 400), maxLag = Math.floor(fs / 70); let best = 0, bestLag = 0;
      let energy = 0; for (let i = 0; i < buf.length; i++) energy += buf[i] * buf[i]; if (energy < 1e-4) return 0;
      for (let lag = minLag; lag <= maxLag; lag++) { let c = 0; for (let i = 0; i < buf.length - lag; i++) c += buf[i] * buf[i + lag]; c /= energy; if (c > best) { best = c; bestLag = lag; } }
      return best > 0.5 ? fs / bestLag : 0;
    }

    tick() {
      if (!this.running) return;
      this.an.getFloatTimeDomainData(this.buf);
      let s = 0, clip = 0; for (let i = 0; i < this.buf.length; i++) { s += this.buf[i] * this.buf[i]; if (Math.abs(this.buf[i]) > 0.99) clip++; }
      const rms = Math.sqrt(s / this.buf.length); const db = 20 * Math.log10(rms + 1e-6); this.rms.push(db); this.clipped += clip; this.frames++;
      const now = performance.now(); const active = db > -45;
      if (active && !this.speaking) { this.speaking = true; this.speechStart = now; if (this.pauseStart) this.pauses.push(now - this.pauseStart); }
      if (!active && this.speaking) { this.speaking = false; this.pauseStart = now; }
      if (active) { const f0 = this.pitch(this.buf, this.ctx.sampleRate); if (f0) { this.f0s.push(f0); this.voiced++; } }
    }

    report() {
      if (!this.running || !this.ws || this.ws.readyState !== 1) return;
      const mean = (a) => (a.length ? a.reduce((x, y) => x + y, 0) / a.length : 0);
      const std = (a) => { const m = mean(a); return a.length > 1 ? Math.sqrt(mean(a.map((v) => (v - m) ** 2))) : 0; };
      const f0Sorted = [...this.f0s].sort((a, b) => a - b);
      const st = (hz) => (hz > 0 ? 12 * Math.log2(hz / 100) : 0);
      const state = this.speaking ? "patient_speaking" : this.remoteSpeaking ? "patient_listening" : "silence";
      const features = {
        loudness_rms_db: mean(this.rms), loudness_std: std(this.rms),
        f0_mean: mean(this.f0s), f0_std: std(this.f0s), f0_range: f0Sorted.length ? st(f0Sorted[Math.floor(f0Sorted.length * 0.95)]) - st(f0Sorted[Math.floor(f0Sorted.length * 0.05)]) : 0,
        voiced_ratio: this.frames ? this.voiced / this.frames : 0,
        pause_count: this.pauses.filter((p) => p > 250).length * 120, // per minute, 0.5 s window
        pause_duration_mean: mean(this.pauses), pause_duration_max: this.pauses.length ? Math.max(...this.pauses) : 0,
        silence_duration: !this.speaking && !this.remoteSpeaking && this.pauseStart ? (performance.now() - this.pauseStart) / 1000 : 0,
      };
      const quality = { audio_quality: this.frames ? Math.max(0, 1 - this.clipped / (this.frames * this.buf.length) * 50) : 0, audio_snr_db: Math.max(0, mean(this.rms) + 60), audio_clipping_ratio: this.frames ? this.clipped / (this.frames * this.buf.length) : 0 };
      this.ws.send(JSON.stringify({ type: "frame", data: { t_ms: Math.round(performance.now() - this.t0), source: "audio", features, quality, speaker_state: state } }));
      if (window.RavanVision) window.RavanVision.setSpeakerState(state);
      this.pauses = []; this.f0s = []; this.rms = []; this.voiced = 0; this.frames = 0; this.clipped = 0;
    }
  }
  window.RavanAudio = new AudioWorker();
})();
