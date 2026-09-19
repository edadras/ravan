import 'dart:async';
import 'dart:js_interop';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:web/web.dart' as web;

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';
import '../../core/models.dart';
import '../../core/realtime.dart';
import 'asr_client.dart';
import 'webrtc_service.dart';
import 'widgets/event_card.dart';

/// Clinician console: large patient video, live observation timeline, transcript, notes.
class DoctorConsoleScreen extends StatefulWidget {
  const DoctorConsoleScreen({super.key, required this.uuid});
  final String uuid;

  @override
  State<DoctorConsoleScreen> createState() => _DoctorConsoleScreenState();
}

class _DoctorConsoleScreenState extends State<DoctorConsoleScreen> {
  late ApiClient api;
  final rtc = WebRtcService();
  RealtimeClient? rt;
  lk.Room? room;
  final events = <BehaviorEvent>[];
  final transcript = <TranscriptSegment>[];
  final Set<String> hiddenTiers = {'observation', 'quality'};
  double minConfidence = 0.6;
  bool analysisEnabled = false;
  Timer? poll;
  final noteCtl = TextEditingController();
  final topicCtl = TextEditingController();
  final scroll = ScrollController();
  AsrClient? asr;
  int? patientId;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    api = AuthScope.of(context).api;
    final join = await api.post('/sessions/${widget.uuid}/join') as Map<String, dynamic>;
    analysisEnabled = join['session']['analysis_enabled'] == true;
    final info = await api.get('/sessions/${widget.uuid}') as Map<String, dynamic>;
    patientId = info['patient_id'] as int?;
    if ((info['consents'] as List).any((c) => c['type'] == 'transcription' && c['withdrawn_at'] == null)) {
      try {
        final mic = await web.window.navigator.mediaDevices.getUserMedia(web.MediaStreamConstraints(audio: true.toJS)).toDart;
        asr = AsrClient(api: api, sessionUuid: widget.uuid, language: context.lang, onSegments: (_) {});
        await asr!.start(mic);
      } catch (_) {}
    }
    if (join['webrtc'] != null) {
      room = await rtc.connect(url: join['webrtc']['url'] as String, token: join['webrtc']['token'] as String, video: true);
      room!.addListener(() => setState(() {}));
    }
    try {
      rt = RealtimeClient(wsUrl: const String.fromEnvironment('RAVAN_WS_URL', defaultValue: 'ws://localhost:8080'), appKey: const String.fromEnvironment('RAVAN_WS_KEY', defaultValue: 'ravan'), api: api);
      await rt!.connect();
      await rt!.subscribe('private-session.${widget.uuid}.clinician');
      rt!.messages.listen((m) {
        if (m.event == 'behavior.event') _addEvent(BehaviorEvent.fromJson(m.data));
        if (m.event == 'transcript.segment') setState(() => transcript.add(TranscriptSegment.fromJson(m.data)));
        if (m.event == 'session.status') setState(() => analysisEnabled = m.data['analysis_enabled'] == true);
      });
    } catch (_) {
      poll = Timer.periodic(const Duration(seconds: 3), (_) => _refresh());
    }
    await _refresh();
    setState(() {});
  }

  Future<void> _refresh() async {
    final since = events.isEmpty ? 0 : events.last.tEndMs + 1;
    final res = await api.get('/sessions/${widget.uuid}/events', query: {'since_ms': '$since'}) as Map<String, dynamic>;
    for (final e in (res['data'] as List)) {
      _addEvent(BehaviorEvent.fromJson(e as Map<String, dynamic>));
    }
    final tr = await api.get('/sessions/${widget.uuid}/transcript') as Map<String, dynamic>;
    setState(() {
      transcript
        ..clear()
        ..addAll((tr['data'] as List).map((s) => TranscriptSegment.fromJson(s as Map<String, dynamic>)));
    });
  }

  void _addEvent(BehaviorEvent e) {
    if (events.any((x) => x.uuid == e.uuid)) return;
    setState(() => events.add(e));
    if (e.tier == 'safety' && mounted) {
      ScaffoldMessenger.of(context).showMaterialBanner(MaterialBanner(
        backgroundColor: Colors.red.shade50,
        content: Text(context.t('safety_banner', {'t': e.timeLabel, 'text': e.context['transcript_text'] ?? ''})),
        actions: [TextButton(onPressed: () => ScaffoldMessenger.of(context).hideCurrentMaterialBanner(), child: Text(context.t('seen')))],
      ));
    }
  }

  Future<void> _review(BehaviorEvent e, String status, {String? note, String? selectedContext}) async {
    await api.post('/events/${e.uuid}/review', {'status': status, if (note != null) 'note': note, if (selectedContext != null) 'selected_context': selectedContext});
    setState(() => e.clinicianStatus = status);
  }

  Future<void> _mark() async {
    await api.post('/sessions/${widget.uuid}/analysis/mark', {'t_ms': events.isEmpty ? 0 : events.last.tEndMs, 'note': noteCtl.text});
    if (noteCtl.text.trim().isNotEmpty) {
      await api.post('/sessions/${widget.uuid}/notes', {'body': noteCtl.text.trim()});
      noteCtl.clear();
    }
  }

  Future<void> _end() async {
    asr?.stop();
    poll?.cancel();
    await rtc.disconnect();
    await api.post('/sessions/${widget.uuid}/end');
    if (mounted) context.go('/report/${widget.uuid}');
  }

  @override
  void dispose() {
    asr?.stop();
    poll?.cancel();
    rt?.dispose();
    rtc.disconnect();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final remote = room?.remoteParticipants.values.expand((p) => p.videoTrackPublications).map((p) => p.track).whereType<lk.VideoTrack>().firstOrNull;
    final visible = events.where((e) => !hiddenTiers.contains(e.tier) && (e.confidence >= minConfidence || e.tier == 'safety')).toList().reversed.toList();
    return Scaffold(
      appBar: AppBar(
        title: Row(children: [
          Text(context.t('console')),
          const SizedBox(width: 16),
          Chip(
            avatar: Icon(analysisEnabled ? Icons.visibility : Icons.visibility_off, size: 16),
            label: Text(context.t(analysisEnabled ? 'analysis_active' : 'analysis_inactive'), style: const TextStyle(fontSize: 12)),
          ),
        ]),
        actions: [
          SizedBox(
            width: 180,
            child: TextField(
              controller: topicCtl,
              decoration: InputDecoration(hintText: context.t('topic_hint'), isDense: true),
              onSubmitted: (v) => api.post('/sessions/${widget.uuid}/analysis/topic', {'topic': v}),
            ),
          ),
          if (patientId != null) TextButton.icon(onPressed: () => context.push('/records/$patientId?session=${widget.uuid}'), icon: const Icon(Icons.folder_shared_outlined), label: Text(context.t('record'))),
          Icon(asr?.running == true ? Icons.mic : Icons.mic_off, size: 18, color: asr?.running == true ? Colors.green : Colors.grey),
          const LanguageSwitcher(),
          TextButton.icon(onPressed: _end, icon: const Icon(Icons.call_end, color: Colors.red), label: Text(context.t('end_and_report'))),
        ],
      ),
      body: Row(children: [
        Expanded(
          flex: 3,
          child: Column(children: [
            Expanded(
              flex: 3,
              child: Container(
                color: Colors.black,
                child: remote != null ? lk.VideoTrackRenderer(remote) : Center(child: Text(context.t('waiting_patient'), style: const TextStyle(color: Colors.white70))),
              ),
            ),
            Expanded(
              flex: 2,
              child: Container(
                color: Colors.white,
                child: ListView.builder(
                  controller: scroll,
                  padding: const EdgeInsets.all(12),
                  itemCount: transcript.length,
                  itemBuilder: (_, i) {
                    final s = transcript[i];
                    final t = '${(s.tStartMs ~/ 60000).toString().padLeft(2, '0')}:${((s.tStartMs ~/ 1000) % 60).toString().padLeft(2, '0')}';
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 3),
                      child: RichText(
                        text: TextSpan(style: const TextStyle(color: Colors.black87, fontSize: 14), children: [
                          TextSpan(text: '$t  ', style: const TextStyle(color: Colors.black45, fontFeatures: [FontFeature.tabularFigures()])),
                          TextSpan(text: '${context.t(s.speaker == 'clinician' ? 'clinician' : 'patient')}: ', style: TextStyle(fontWeight: FontWeight.w700, color: s.speaker == 'clinician' ? Colors.indigo : Colors.teal)),
                          TextSpan(text: s.text),
                        ]),
                      ),
                    );
                  },
                ),
              ),
            ),
            Container(
              color: Colors.grey.shade100,
              padding: const EdgeInsets.all(8),
              child: Row(children: [
                Expanded(child: TextField(controller: noteCtl, decoration: InputDecoration(hintText: context.t('note_hint'), isDense: true, border: const OutlineInputBorder()))),
                const SizedBox(width: 8),
                FilledButton.icon(onPressed: _mark, icon: const Icon(Icons.bookmark_add), label: Text(context.t('mark_moment'))),
              ]),
            ),
          ]),
        ),
        SizedBox(
          width: 420,
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.all(8),
              child: Column(children: [
                Wrap(spacing: 4, children: [
                  for (final t in tiers)
                    FilterChip(
                      label: Text(context.t('tier_$t'), style: const TextStyle(fontSize: 11)),
                      selected: !hiddenTiers.contains(t),
                      selectedColor: (tierColors[t] ?? Colors.grey).withOpacity(0.2),
                      onSelected: (v) => setState(() => v ? hiddenTiers.remove(t) : hiddenTiers.add(t)),
                    ),
                ]),
                Row(children: [
                  Text(context.t('min_confidence'), style: const TextStyle(fontSize: 12)),
                  Expanded(child: Slider(value: minConfidence, min: 0.3, max: 0.95, divisions: 13, label: '${(minConfidence * 100).round()}%', onChanged: (v) => setState(() => minConfidence = v))),
                ]),
              ]),
            ),
            const Divider(height: 1),
            Expanded(
              child: visible.isEmpty
                  ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(context.t('no_events_yet'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54))))
                  : ListView.builder(
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      itemCount: visible.length,
                      itemBuilder: (_, i) => EventCard(
                        event: visible[i],
                        onReview: (status, {note, selectedContext}) => _review(visible[i], status, note: note, selectedContext: selectedContext),
                        onOpenTranscript: () {
                          final idx = transcript.indexWhere((s) => s.tEndMs >= visible[i].tStartMs - 20000);
                          if (idx >= 0) scroll.animateTo(idx * 28.0, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
                        },
                      ),
                    ),
            ),
            Container(
              padding: const EdgeInsets.all(8),
              color: Colors.grey.shade100,
              child: Text(context.t('console_footer'), style: const TextStyle(fontSize: 11, color: Colors.black54), textAlign: TextAlign.center),
            ),
          ]),
        ),
      ]),
    );
  }
}
