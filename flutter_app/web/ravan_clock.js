/**
 * One time origin for every recorder in a session.
 *
 * The vision worker, the audio worker and the microphone recorder each used to
 * start their own `performance.now()` clock when they happened to be switched
 * on. The patient's and the clinician's browsers did the same, independently.
 * Every timestamp in the pipeline therefore had a different, unknown origin,
 * and the response-latency signals — "the answer came 4.7 s after the question"
 * — carried the difference between two of those origins as pure error.
 *
 * Now every t_ms is milliseconds since the session's own start, as the server
 * recorded it. The device clock is only trusted after being corrected against
 * the server's, using the same one-sample estimate NTP starts from: with a
 * symmetric round trip, the server's clock at the moment it replied was
 * `serverNowMs`, which was `rtt / 2` ago.
 *
 *   RavanClock.sync({ serverNowMs, sessionStartMs, requestSentAt })
 *   RavanClock.nowMs()   -> ms since the session started, on the shared origin
 *   RavanClock.synced    -> false if the page never managed to sync
 */
(function () {
  const clock = {
    offsetMs: 0,
    sessionStartMs: null,
    synced: false,
    uncertaintyMs: null,

    sync({ serverNowMs, sessionStartMs, requestSentAt }) {
      const received = Date.now();
      const rtt = requestSentAt ? Math.max(0, received - requestSentAt) : 0;
      this.offsetMs = serverNowMs + rtt / 2 - received;
      this.sessionStartMs = sessionStartMs;
      this.synced = true;
      // Half the round trip is the worst the one-sample estimate can be off by,
      // assuming the trip was symmetric. Reported so the analysis service can
      // see how much slack a latency measurement deserves.
      this.uncertaintyMs = Math.round(rtt / 2);
      return this.uncertaintyMs;
    },

    /** Milliseconds since the session started, on the shared origin. */
    nowMs() {
      if (!this.synced) return Math.round(performance.now());
      return Math.round(Date.now() + this.offsetMs - this.sessionStartMs);
    },
  };

  window.RavanClock = clock;
})();
