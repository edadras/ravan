import 'dart:async';
import 'dart:convert';

import 'package:web_socket_channel/web_socket_channel.dart';

import 'api_client.dart';

/// Pusher-protocol client (works with Laravel Reverb or Pusher) for private session channels.
///
/// Channel auth goes through Laravel's /broadcasting/auth using the Sanctum token.
class RealtimeClient {
  RealtimeClient({required this.wsUrl, required this.appKey, required this.api});

  final String wsUrl;
  final String appKey;
  final ApiClient api;
  WebSocketChannel? _ch;
  String? _socketId;
  final _controller = StreamController<RealtimeMessage>.broadcast();
  final _pending = <String>{};

  Stream<RealtimeMessage> get messages => _controller.stream;

  Future<void> connect() async {
    _ch = WebSocketChannel.connect(Uri.parse('$wsUrl/app/$appKey?protocol=7&client=ravan&version=0.1'));
    _ch!.stream.listen((raw) {
      final m = jsonDecode(raw as String) as Map<String, dynamic>;
      final event = m['event'] as String;
      if (event == 'pusher:connection_established') {
        _socketId = (jsonDecode(m['data'] as String) as Map)['socket_id'] as String;
        for (final c in _pending) {
          _subscribe(c);
        }
      } else if (!event.startsWith('pusher')) {
        final data = m['data'] is String ? jsonDecode(m['data'] as String) : m['data'];
        _controller.add(RealtimeMessage(m['channel'] as String?, event, data as Map<String, dynamic>));
      }
    }, onError: (e) => _controller.addError(e));
  }

  Future<void> subscribe(String channel) async {
    if (_socketId == null) {
      _pending.add(channel);
      return;
    }
    await _subscribe(channel);
  }

  Future<void> _subscribe(String channel) async {
    final auth = await api.post('/../broadcasting/auth', {'socket_id': _socketId, 'channel_name': channel}) as Map<String, dynamic>;
    _ch!.sink.add(jsonEncode({'event': 'pusher:subscribe', 'data': {'channel': channel, 'auth': auth['auth']}}));
  }

  void dispose() {
    _ch?.sink.close();
    _controller.close();
  }
}

class RealtimeMessage {
  RealtimeMessage(this.channel, this.event, this.data);
  final String? channel;
  final String event;
  final Map<String, dynamic> data;
}
