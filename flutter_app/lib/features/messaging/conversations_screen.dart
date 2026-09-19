import 'dart:async';

import 'package:flutter/material.dart';

import '../../core/auth_store.dart';
import '../../core/l10n.dart';
import '../../core/realtime.dart';

/// Direct patient ↔ clinician messaging with delivery/read receipts; pushed over the per-user channel.
class ConversationsScreen extends StatefulWidget {
  const ConversationsScreen({super.key, this.openWithUserId});
  final int? openWithUserId;

  @override
  State<ConversationsScreen> createState() => _ConversationsScreenState();
}

class _ConversationsScreenState extends State<ConversationsScreen> {
  List<Map<String, dynamic>> conversations = [];
  Map<String, dynamic>? current;
  List<Map<String, dynamic>> messages = [];
  final input = TextEditingController();
  RealtimeClient? rt;
  Timer? poll;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    final auth = AuthScope.of(context);
    if (widget.openWithUserId != null) {
      current = await auth.api.post('/conversations/with/${widget.openWithUserId}') as Map<String, dynamic>;
    }
    await _loadList();
    if (current != null) await _open(current!);
    try {
      rt = RealtimeClient(wsUrl: const String.fromEnvironment('RAVAN_WS_URL', defaultValue: 'ws://localhost:8080'), appKey: const String.fromEnvironment('RAVAN_WS_KEY', defaultValue: 'ravan'), api: auth.api);
      await rt!.connect();
      await rt!.subscribe('private-user.${auth.user!.id}');
      rt!.messages.listen((m) {
        if (m.event == 'conversation.message') {
          if (current != null && m.data['conversation_id'] == current!['id']) _open(current!);
          _loadList();
        }
      });
    } catch (_) {
      poll = Timer.periodic(const Duration(seconds: 5), (_) { if (current != null) _open(current!); _loadList(); });
    }
  }

  Future<void> _loadList() async {
    final res = await AuthScope.of(context).api.get('/conversations') as List;
    setState(() => conversations = res.cast<Map<String, dynamic>>());
  }

  Future<void> _open(Map<String, dynamic> c) async {
    final api = AuthScope.of(context).api;
    final res = await api.get('/conversations/${c['id']}/messages') as Map<String, dynamic>;
    await api.post('/conversations/${c['id']}/read');
    setState(() { current = c; messages = (res['data'] as List).cast<Map<String, dynamic>>(); });
  }

  Future<void> _send() async {
    final t = input.text.trim();
    if (t.isEmpty || current == null) return;
    input.clear();
    await AuthScope.of(context).api.post('/conversations/${current!['id']}/messages', {'body': t});
    await _open(current!);
  }

  @override
  void dispose() {
    poll?.cancel();
    rt?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final me = AuthScope.of(context).user!.id;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('messages')), actions: const [LanguageSwitcher()]),
      body: Row(children: [
        SizedBox(
          width: 280,
          child: conversations.isEmpty
              ? Center(child: Text(context.t('no_conversations')))
              : ListView(children: [
                  for (final c in conversations)
                    ListTile(
                      selected: current?['id'] == c['id'],
                      title: Text((me == c['patient_id'] ? c['clinician']?['name'] : c['patient']?['name']) ?? ''),
                      trailing: (c['unread_count'] ?? 0) > 0 ? CircleAvatar(radius: 11, child: Text('${c['unread_count']}', style: const TextStyle(fontSize: 11))) : null,
                      onTap: () => _open(c),
                    ),
                ]),
        ),
        const VerticalDivider(width: 1),
        Expanded(
          child: current == null
              ? const SizedBox.shrink()
              : Column(children: [
                  Expanded(
                    child: ListView(padding: const EdgeInsets.all(12), children: [
                      for (final m in messages)
                        Align(
                          alignment: m['sender_id'] == me ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
                          child: Card(
                            color: m['sender_id'] == me ? Colors.indigo.shade50 : null,
                            child: Padding(padding: const EdgeInsets.all(8), child: Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
                              Text(m['body'] as String),
                              if (m['sender_id'] == me) Text(m['read_at'] != null ? context.t('read') : (m['delivered_at'] != null ? context.t('delivered') : ''), style: const TextStyle(fontSize: 10, color: Colors.black45)),
                            ])),
                          ),
                        ),
                    ]),
                  ),
                  Padding(padding: const EdgeInsets.all(8), child: Row(children: [
                    Expanded(child: TextField(controller: input, decoration: InputDecoration(hintText: context.t('new_message'), border: const OutlineInputBorder(), isDense: true), onSubmitted: (_) => _send())),
                    IconButton(onPressed: _send, icon: const Icon(Icons.send)),
                  ])),
                ]),
        ),
      ]),
    );
  }
}
