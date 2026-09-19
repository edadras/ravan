import 'dart:js_interop';

import 'package:web/web.dart' as web;

/// Dart ↔ JS bridge to the on-device feature extractors (web/vision_worker.js, web/audio_worker.js).
///
/// The patient's browser computes derived features locally and streams them straight to the
/// analysis service. The Laravel backend never sees frames; the analysis service never sees pixels.
@JS('RavanVision')
external JSObject get _vision;

@JS('RavanAudio')
external JSObject get _audio;

extension type _VisionApi(JSObject o) implements JSObject {
  external JSPromise start(JSObject opts);
  external void pause();
  external void resume();
  external void stop();
}

extension type _AudioApi(JSObject o) implements JSObject {
  external void start(JSObject opts);
  external void stop();
  external void setRemoteSpeaking(bool v);
}

class AnalysisBridge {
  bool _running = false;
  bool get running => _running;

  /// [videoEl] is the local <video> element bound to the camera track; [wsUrl]/[token] come from POST /sessions/{uuid}/join.
  Future<void> start({required web.HTMLVideoElement videoEl, required web.MediaStream micStream, required String wsUrl, required String token, void Function(String state, List<dynamic>? events)? onStatus}) async {
    final opts = JSObject()
      ..setProperty('videoEl'.toJS, videoEl)
      ..setProperty('wsUrl'.toJS, wsUrl.toJS)
      ..setProperty('token'.toJS, token.toJS)
      ..setProperty('fps'.toJS, 15.toJS)
      ..setProperty('onStatus'.toJS, ((JSObject s) {
        final state = s.getProperty<JSString>('state'.toJS).toDart;
        final ev = s.getProperty<JSAny?>('events'.toJS);
        onStatus?.call(state, ev.isUndefinedOrNull ? null : (ev as JSArray).toDart);
      }).toJS);
    await _VisionApi(_vision).start(opts).toDart;
    final ws = _vision.getProperty<JSObject>('ws'.toJS);
    final t0 = _vision.getProperty<JSAny?>('t0'.toJS);
    _AudioApi(_audio).start(JSObject()
      ..setProperty('stream'.toJS, micStream)
      ..setProperty('ws'.toJS, ws)
      ..setProperty('t0'.toJS, t0));
    _running = true;
  }

  void pause() => _VisionApi(_vision).pause();
  void resume() => _VisionApi(_vision).resume();
  void setRemoteSpeaking(bool v) => _AudioApi(_audio).setRemoteSpeaking(v);

  void stop() {
    if (!_running) return;
    _VisionApi(_vision).stop();
    _AudioApi(_audio).stop();
    _running = false;
  }
}
