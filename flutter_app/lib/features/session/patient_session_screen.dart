import 'dart:js_interop';

import 'package:flutter/material.dart';
import 'package:livekit_client/livekit_client.dart' as lk;
import 'package:web/web.dart' as web;

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';
import 'analysis_bridge.dart';
import 'asr_client.dart';
import 'consent_dialog.dart';
import 'webrtc_service.dart';

/// Patient side: video call + chat + a single, always-visible switch for behavioural analysis.
/// The patient never sees behaviour events.
class PatientSessionScreen extends StatefulWidget {
  const PatientSessionScreen({super.key, required this.uuid});
  final String uuid;

  @override
  State<PatientSessionScreen> createState() => _PatientSessionScreenState();
}

class _PatientSessionScreenState extends State<PatientSessionScreen> {
  late ApiClient api;
  final rtc = WebRtcService();
  final bridge = AnalysisBridge();
  Map<String, dynamic>? session;
  Map<String, dynamic>? joinInfo;
  bool analysisOn = false;
  bool cameraOn = true;
  String statusKey = 'connecting';
  final chat = <Map<String, dynamic>>[];
  final chatCtl = TextEditingController();
  lk.Room? room;
  AsrClient? asr;
  final transcript = <Map<String, dynamic>>[];
  web.MediaStream? mic;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _boot());
  }

  Future<void> _boot() async {
    api = AuthScope.of(context).api;
    session = await api.get('/sessions/${widget.uuid}') as Map<String, dynamic>;
    final consented = (session!['consents'] as List).where((c) => c['withdrawn_at'] == null).map((c) => c['type']).toSet();
    if (session!['mode'] != 'text' && !consented.contains('video_call')) {
      final texts = (await api.get('/consents/texts', query: {'locale': context.lang}) as List).cast<Map<String, dynamic>>();
      final granted = await ConsentDialog.show(context, texts);
      if (granted != null && granted.isNotEmpty) {
        await api.post('/sessions/${widget.uuid}/consents', {'types': granted.toList()});
      }
    }
    joinInfo = await api.post('/sessions/${widget.uuid}/join', {'browser': web.window.navigator.userAgent}) as Map<String, dynamic>;
    if (joinInfo!['webrtc'] != null) {
      room = await rtc.connect(url: joinInfo!['webrtc']['url'] as String, token: joinInfo!['webrtc']['token'] as String, video: session!['mode'] == 'video');
      room!.addListener(() => setState(() {}));
      room!.createListener().on<lk.ActiveSpeakersChangedEvent>((e) => bridge.setRemoteSpeaking(e.speakers.any((p) => p is lk.RemoteParticipant)));
    }
    setState(() => statusKey = 'in_session');
    if (joinInfo!['analysis_allowed'] == true) await _toggleAnalysis(true);
    if (consented.contains('transcription') || (session!['consents'] as List).any((c) => c['type'] == 'transcription' && c['withdrawn_at'] == null)) await _startAsr();
  }

  Future<void> _toggleAnalysis(bool on) async {
    try {
      if (on) {
        await api.post('/sessions/${widget.uuid}/analysis/start');
        if (!bridge.running) {
          final videoEl = web.document.querySelector('video') as web.HTMLVideoElement?;
          final mic = await web.window.navigator.mediaDevices.getUserMedia(web.MediaStreamConstraints(audio: true.toJS)).toDart;
          if (videoEl != null) {
            await bridge.start(videoEl: videoEl, micStream: mic, wsUrl: joinInfo!['analysis_ingest']['ws_url'] as String, token: joinInfo!['analysis_ingest']['token'] as String);
          }
        } else {
          bridge.resume();
        }
      } else {
        await api.post('/sessions/${widget.uuid}/analysis/pause');
        bridge.pause();
      }
      setState(() => analysisOn = on);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _startAsr() async {
    try {
      mic ??= await web.window.navigator.mediaDevices.getUserMedia(web.MediaStreamConstraints(audio: true.toJS)).toDart;
      asr = AsrClient(api: api, sessionUuid: widget.uuid, language: context.lang, onSegments: (segs) => setState(() => transcript.addAll(segs.cast<Map<String, dynamic>>())));
      await asr!.start(mic!);
      setState(() {});
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('mic_permission_needed'))));
    }
  }

  Future<void> _withdraw() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(context.t('withdraw_title')),
        content: Text(context.t('withdraw_body')),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.t('cancel'))), FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(context.t('withdraw_confirm')))],
      ),
    );
    if (ok == true) {
      bridge.stop();
      await api.post('/sessions/${widget.uuid}/consents/withdraw', {'type': 'behavior_analysis'});
      setState(() => analysisOn = false);
    }
  }

  Future<void> _end() async {
    asr?.stop();
    bridge.stop();
    await rtc.disconnect();
    await api.post('/sessions/${widget.uuid}/end');
    if (mounted) Navigator.of(context).pop();
  }

  @override
  void dispose() {
    asr?.stop();
    bridge.stop();
    rtc.disconnect();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final remote = room?.remoteParticipants.values.expand((p) => p.videoTrackPublications).map((p) => p.track).whereType<lk.VideoTrack>().firstOrNull;
    return Scaffold(
      appBar: AppBar(title: Text(context.t('session_title', {'status': context.t(statusKey)})), actions: [
        const LanguageSwitcher(),
        TextButton.icon(onPressed: _end, icon: const Icon(Icons.call_end, color: Colors.red), label: Text(context.t('end_session'))),
      ]),
      body: Row(children: [
        Expanded(
          flex: 3,
          child: Column(children: [
            Expanded(
              child: Container(
                color: Colors.black,
                child: Stack(children: [
                  if (remote != null) lk.VideoTrackRenderer(remote) else Center(child: Text(context.t('waiting_clinician'), style: const TextStyle(color: Colors.white70))),
                  if (rtc.localVideo != null && cameraOn)
                    Positioned(bottom: 12, left: 12, width: 200, height: 150, child: lk.VideoTrackRenderer(rtc.localVideo!)),
                ]),
              ),
            ),
            Container(
              color: Colors.white,
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
              child: Row(children: [
                IconButton(
                  tooltip: context.t(cameraOn ? 'camera_off' : 'camera_on'),
                  onPressed: () async { await rtc.setCameraEnabled(!cameraOn); setState(() => cameraOn = !cameraOn); },
                  icon: Icon(cameraOn ? Icons.videocam : Icons.videocam_off),
                ),
                const SizedBox(width: 16),
                if (joinInfo?['analysis_allowed'] == true) ...[
                  Switch(value: analysisOn, onChanged: _toggleAnalysis),
                  Text(context.t(analysisOn ? 'analysis_on_label' : 'analysis_paused_label')),
                  const SizedBox(width: 12),
                  TextButton(onPressed: _withdraw, child: Text(context.t('withdraw_consent'))),
                ] else
                  Text(context.t('analysis_disabled_label')),
                const Spacer(),
                Icon(asr?.running == true ? Icons.mic : Icons.mic_off, size: 18, color: asr?.running == true ? Colors.green : Colors.grey),
                Text(context.t(asr?.running == true ? 'transcription_on' : 'transcription_off'), style: const TextStyle(fontSize: 12)),
              ]),
            ),
          ]),
        ),
        SizedBox(
          width: 320,
          child: Column(children: [
            if (transcript.isNotEmpty)
              SizedBox(height: 160, child: ListView(padding: const EdgeInsets.all(8), children: [
                Text(context.t('live_transcript'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                for (final s in transcript.reversed.take(8)) Text('${s['speaker'] == 'clinician' ? context.t('clinician') : context.t('patient')}: ${s['text']}', style: const TextStyle(fontSize: 12)),
              ])),
            Expanded(
              child: ListView.builder(
                padding: const EdgeInsets.all(12),
                itemCount: chat.length,
                itemBuilder: (_, i) => Align(
                  alignment: chat[i]['mine'] == true ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
                  child: Card(child: Padding(padding: const EdgeInsets.all(8), child: Text(chat[i]['body'] as String))),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.all(8),
              child: TextField(
                controller: chatCtl,
                decoration: InputDecoration(hintText: context.t('message_hint'), border: const OutlineInputBorder()),
                onSubmitted: (v) async {
                  if (v.trim().isEmpty) return;
                  await api.post('/sessions/${widget.uuid}/messages', {'body': v});
                  setState(() { chat.add({'body': v, 'mine': true}); chatCtl.clear(); });
                },
              ),
            ),
          ]),
        ),
      ]),
    );
  }
}
