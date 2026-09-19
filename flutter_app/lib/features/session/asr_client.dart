import 'dart:convert';
import 'dart:js_interop';

import 'package:http/http.dart' as http;
import 'package:web/web.dart' as web;

import '../../core/api_client.dart';

@JS('RavanAsr')
external JSObject get _asr;

extension type _AsrApi(JSObject o) implements JSObject {
  external void start(JSObject opts);
  external void stop();
}

/// Records the local microphone in 5-second chunks (web/asr_recorder.js) and uploads each one
/// to the backend for transcription. Requires the patient's transcription consent, which the
/// backend enforces. Nothing is stored in the browser.
class AsrClient {
  AsrClient({required this.api, required this.sessionUuid, required this.language, this.onSegments});

  final ApiClient api;
  final String sessionUuid;
  final String language;
  final void Function(List<dynamic> segments)? onSegments;
  bool running = false;

  Future<void> start(web.MediaStream mic) async {
    final opts = JSObject()
      ..setProperty('stream'.toJS, mic)
      ..setProperty('chunkMs'.toJS, 5000.toJS)
      ..setProperty('onChunk'.toJS, ((web.Blob blob, JSNumber tStart, JSNumber dur) {
        _upload(blob, tStart.toDartInt, dur.toDartInt);
      }).toJS);
    _AsrApi(_asr).start(opts);
    running = true;
  }

  Future<void> _upload(web.Blob blob, int tStartMs, int durationMs) async {
    try {
      final buf = await blob.arrayBuffer().toDart;
      final bytes = buf.toDart.asUint8List();
      final req = http.MultipartRequest('POST', Uri.parse('${api.baseUrl}/sessions/$sessionUuid/asr/chunk'))
        ..headers['Authorization'] = 'Bearer ${api.token}'
        ..headers['Accept'] = 'application/json'
        ..headers['Accept-Language'] = language
        ..fields['t_start_ms'] = '$tStartMs'
        ..fields['duration_ms'] = '$durationMs'
        ..fields['language'] = language
        ..files.add(http.MultipartFile.fromBytes('audio', bytes, filename: 'chunk.webm'));
      final res = await http.Response.fromStream(await req.send());
      if (res.statusCode < 300 && res.body.isNotEmpty && onSegments != null) {
        final m = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
        onSegments!((m['segments'] ?? []) as List);
      }
    } catch (_) {
      // A lost chunk is skipped; the next chunk continues. Never block the call.
    }
  }

  void stop() {
    if (!running) return;
    _AsrApi(_asr).stop();
    running = false;
  }
}
