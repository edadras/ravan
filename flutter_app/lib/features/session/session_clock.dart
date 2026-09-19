import 'dart:js_interop';

/// Dart side of web/ravan_clock.js.
///
/// Every recorder in a session — the vision worker, the audio worker and the
/// microphone recorder, in both participants' browsers — timestamps against the
/// session's start as the server recorded it, rather than against whenever that
/// particular recorder happened to be switched on. Without this, the delay
/// between a clinician's question and a patient's answer was measured across
/// two unrelated origins, and the difference between them went straight into
/// the response-latency signals the clinician is shown.
@JS('RavanClock')
external JSObject? get _clock;

extension type _ClockApi(JSObject o) implements JSObject {
  external JSNumber sync(JSObject opts);
  external JSBoolean get synced;
}

class SessionClock {
  /// [clock] is the `clock` object from POST /sessions/{uuid}/join;
  /// [requestSentAtMs] is when that request left this browser, which lets the
  /// clock take half the round trip off the server's timestamp.
  static int? sync(Map<String, dynamic>? clock, int requestSentAtMs) {
    final api = _clock;
    if (api == null || clock == null) return null;
    final serverNow = clock['server_now_ms'];
    final sessionStart = clock['session_start_ms'];
    if (serverNow is! num || sessionStart is! num) return null;
    final opts = JSObject()
      ..setProperty('serverNowMs'.toJS, serverNow.toDouble().toJS)
      ..setProperty('sessionStartMs'.toJS, sessionStart.toDouble().toJS)
      ..setProperty('requestSentAt'.toJS, requestSentAtMs.toDouble().toJS);
    return _ClockApi(api).sync(opts).toDartInt;
  }

  static bool get synced {
    final api = _clock;
    return api != null && _ClockApi(api).synced.toDart;
  }
}
