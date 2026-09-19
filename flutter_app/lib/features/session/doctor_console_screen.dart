import 'dart:async';

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:livekit_client/livekit_client.dart' as lk;

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/models.dart';
import '../../core/realtime.dart';
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

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    api = AuthScope.of(context).api;
    final join = await api.post('/sessions/${widget.uuid}/join') as Map<String, dynamic>;
    analysisEnabled = join['session']['analysis_enabled'] == true;
    if (join['webrtc'] != null) {
      room = await rtc.connect(url: join['webrtc']['url'] as String, token: join['webrtc']['token'] as String, video: true);
      room!.addListener(() => setState(() {}));
    }
    // realtime (Reverb / Pusher); falls back to polling if it cannot connect
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
        content: Text('عبارت صریح مرتبط با ایمنی در متن (${e.timeLabel}): «${e.context['transcript_text'] ?? ''}» — فقط ارجاع به متن، بدون امتیاز خطر.'),
        actions: [TextButton(onPressed: () => ScaffoldMessenger.of(context).hideCurrentMaterialBanner(), child: const Text('دیدم'))],
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
    poll?.cancel();
    await rtc.disconnect();
    await api.post('/sessions/${widget.uuid}/end');
    if (mounted) context.go('/report/${widget.uuid}');
  }

  @override
  void dispose() {
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
          const Text('کنسول درمانگر'),
          const SizedBox(width: 16),
          Chip(
            avatar: Icon(analysisEnabled ? Icons.visibility : Icons.visibility_off, size: 16),
            label: Text(analysisEnabled ? 'تحلیل رفتاری فعال (با رضایت بیمار)' : 'تحلیل رفتاری غیرفعال', style: const TextStyle(fontSize: 12)),
          ),
        ]),
        actions: [
          SizedBox(
            width: 180,
            child: TextField(
              controller: topicCtl,
              decoration: const InputDecoration(hintText: 'موضوع فعلی (مثلاً خانواده)', isDense: true),
              onSubmitted: (v) => api.post('/sessions/${widget.uuid}/analysis/topic', {'topic': v}),
            ),
          ),
          const SizedBox(width: 12),
          TextButton.icon(onPressed: _end, icon: const Icon(Icons.call_end, color: Colors.red), label: const Text('پایان و گزارش')),
        ],
      ),
      body: Row(children: [
        // ---------------- video + transcript
        Expanded(
          flex: 3,
          child: Column(children: [
            Expanded(
              flex: 3,
              child: Container(
                color: Colors.black,
                child: remote != null ? lk.VideoTrackRenderer(remote) : const Center(child: Text('در انتظار بیمار…', style: TextStyle(color: Colors.white70))),
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
                          TextSpan(text: s.speaker == 'clinician' ? 'درمانگر: ' : 'بیمار: ', style: TextStyle(fontWeight: FontWeight.w700, color: s.speaker == 'clinician' ? Colors.indigo : Colors.teal)),
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
                Expanded(child: TextField(controller: noteCtl, decoration: const InputDecoration(hintText: 'یادداشت بالینی (فقط شما می‌نویسید)', isDense: true, border: OutlineInputBorder()))),
                const SizedBox(width: 8),
                FilledButton.icon(onPressed: _mark, icon: const Icon(Icons.bookmark_add), label: const Text('علامت‌گذاری این لحظه')),
              ]),
            ),
          ]),
        ),
        // ---------------- timeline
        SizedBox(
          width: 420,
          child: Column(children: [
            Padding(
              padding: const EdgeInsets.all(8),
              child: Column(children: [
                Wrap(spacing: 4, children: [
                  for (final t in tierLabelsFa.keys)
                    FilterChip(
                      label: Text(tierLabelsFa[t]!, style: const TextStyle(fontSize: 11)),
                      selected: !hiddenTiers.contains(t),
                      selectedColor: (tierColors[t] ?? Colors.grey).withOpacity(0.2),
                      onSelected: (v) => setState(() => v ? hiddenTiers.remove(t) : hiddenTiers.add(t)),
                    ),
                ]),
                Row(children: [
                  const Text('حداقل اطمینان', style: TextStyle(fontSize: 12)),
                  Expanded(child: Slider(value: minConfidence, min: 0.3, max: 0.95, divisions: 13, label: '${(minConfidence * 100).round()}٪', onChanged: (v) => setState(() => minConfidence = v))),
                ]),
              ]),
            ),
            const Divider(height: 1),
            Expanded(
              child: visible.isEmpty
                  ? const Center(child: Padding(padding: EdgeInsets.all(24), child: Text('هنوز مشاهده‌ای ثبت نشده. تا ساخته شدن خط پایه (حدود ۵ دقیقه) فقط پرچم‌های کیفیت نمایش داده می‌شوند.', textAlign: TextAlign.center, style: TextStyle(color: Colors.black54))))
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
              child: const Text('همه موارد «مشاهده» هستند، نه تشخیص. مقایسه فقط با خط پایه همین بیمار در همین جلسه انجام می‌شود.', style: TextStyle(fontSize: 11, color: Colors.black54), textAlign: TextAlign.center),
            ),
          ]),
        ),
      ]),
    );
  }
}
