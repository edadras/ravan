/**
 * Ravan ASR recorder: records the LOCAL microphone in short chunks and hands each chunk to Dart
 * for upload to POST /api/sessions/{uuid}/asr/chunk. Nothing is stored in the browser.
 *
 * window.RavanAsr.start({ stream, chunkMs, onChunk(blob, tStartMs, durationMs) }) / stop()
 */
(function () {
  class AsrRecorder {
    start({ stream, chunkMs = 5000, t0, onChunk }) {
      this.stop();
      this.t0 = t0 || performance.now();
      this.onChunk = onChunk;
      this.chunkMs = chunkMs;
      this.stream = stream;
      this.running = true;
      this._cycle();
    }
    _cycle() {
      if (!this.running) return;
      const mime = MediaRecorder.isTypeSupported("audio/webm;codecs=opus") ? "audio/webm;codecs=opus" : "audio/webm";
      const rec = new MediaRecorder(this.stream, { mimeType: mime, audioBitsPerSecond: 32000 });
      const started = performance.now();
      const parts = [];
      rec.ondataavailable = (e) => { if (e.data && e.data.size > 0) parts.push(e.data); };
      rec.onstop = () => {
        const blob = new Blob(parts, { type: "audio/webm" });
        const dur = Math.round(performance.now() - started);
        if (blob.size > 2000 && this.onChunk) this.onChunk(blob, Math.round(started - this.t0), dur);
        this._cycle();
      };
      rec.start();
      this.current = rec;
      setTimeout(() => { if (rec.state !== "inactive") rec.stop(); }, this.chunkMs);
    }
    stop() {
      this.running = false;
      try { if (this.current && this.current.state !== "inactive") this.current.stop(); } catch (_) { }
      this.current = null;
    }
  }
  window.RavanAsr = new AsrRecorder();
})();
