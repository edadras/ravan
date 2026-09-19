import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// End-of-session report: structured observations + the AI *draft* the clinician must Accept / Edit / Reject.
class ReportScreen extends StatefulWidget {
  const ReportScreen({super.key, required this.uuid});
  final String uuid;

  @override
  State<ReportScreen> createState() => _ReportScreenState();
}

class _ReportScreenState extends State<ReportScreen> {
  late ApiClient api;
  Map<String, dynamic>? report;
  final items = <Map<String, dynamic>>[];
  final summaryCtl = TextEditingController();
  String? error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    api = AuthScope.of(context).api;
    try {
      report = await api.get('/sessions/${widget.uuid}/report') as Map<String, dynamic>;
      final s = report!['structured'] as Map<String, dynamic>;
      items
        ..clear()
        ..add({'key': 'ai_summary', 'ai_text': report!['ai_draft_summary'] ?? '', 'clinician_text': report!['ai_draft_summary'] ?? '', 'status': 'draft', 'title_key': 'ai_summary'});
      for (final c in ((s['strongest_changes'] ?? []) as List).take(10)) {
        items.add({'key': 'change:${c['event_id']}', 'ai_text': context.t('vs_baseline', {'t': c['t'], 'obs': context.pick(c['observation'] as Map<String, dynamic>?), 'cur': c['observed'], 'base': c['baseline']}), 'clinician_text': '', 'status': 'draft', 'title_key': 'notable_change'});
      }
      for (final c in ((s['clusters'] ?? []) as List)) {
        items.add({'key': 'cluster:${c['event_id']}', 'ai_text': '${c['t']} — ${context.pick(c['observation'] as Map<String, dynamic>?)}; ${context.t('members')}: ${(c['members'] as List).length}', 'clinician_text': '', 'status': 'draft', 'title_key': 'cluster'});
      }
      setState(() {});
    } on ApiException catch (e) {
      setState(() => error = e.message);
    }
  }

  Future<void> _submit(bool finalize) async {
    await api.post('/sessions/${widget.uuid}/report/review', {
      'items': items.map((i) => {'key': i['key'], 'ai_text': i['ai_text'], 'clinician_text': i['clinician_text'], 'status': i['status']}).toList(),
      'summary': summaryCtl.text,
      'finalize': finalize,
    });
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t(finalize ? 'finalized_msg' : 'saved_msg'))));
  }

  @override
  Widget build(BuildContext context) {
    if (error != null) return Scaffold(appBar: AppBar(title: Text(context.t('report'))), body: Center(child: Text(error!)));
    if (report == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final s = report!['structured'] as Map<String, dynamic>;
    final conv = (s['conversation'] ?? {}) as Map<String, dynamic>;
    String mmss(num ms) => '${(ms ~/ 60000).toString().padLeft(2, '0')}:${((ms ~/ 1000) % 60).toString().padLeft(2, '0')}';
    return Scaffold(
      appBar: AppBar(title: Text(context.t('report_title')), actions: const [LanguageSwitcher()]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${context.t('duration')}: ${mmss(s['duration_ms'] ?? 0)}'),
              Text('${context.t('clinician_speech')}: ${mmss(conv['clinician_speaking_ms'] ?? 0)}   ${context.t('patient_speech')}: ${mmss(conv['patient_speaking_ms'] ?? 0)}   ${context.t('silence')}: ${mmss(conv['silence_ms'] ?? 0)}'),
              Text('${context.t('notable_changes')}: ${s['behavioral_observations']?['notable_change_events'] ?? 0}   ${context.t('clusters')}: ${s['behavioral_observations']?['clusters'] ?? 0}'),
              Text('${context.t('baseline_quality')}: ${(s['baseline']?['quality_fraction'] ?? 0)}   ${context.t('coverage')}: ${s['baseline']?['coverage_s'] ?? 0} ${context.t('seconds')}', style: const TextStyle(color: Colors.black54)),
              const SizedBox(height: 6),
              Text(context.pick(s['disclaimer'] as Map<String, dynamic>?), style: const TextStyle(fontSize: 12, color: Colors.deepOrange)),
            ]),
          ),
        ),
        const SizedBox(height: 12),
        for (final it in items)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t(it['title_key'] as String), style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text(it['ai_text'] as String, style: const TextStyle(fontSize: 13)),
                if (it['status'] == 'edited')
                  TextField(
                    decoration: InputDecoration(labelText: context.t('your_text'), border: const OutlineInputBorder()),
                    maxLines: 3,
                    controller: TextEditingController(text: it['clinician_text'] as String),
                    onChanged: (v) => it['clinician_text'] = v,
                  ),
                Row(children: [
                  for (final st in const ['accepted', 'edited', 'rejected'])
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 6),
                      child: ChoiceChip(label: Text(context.t(st)), selected: it['status'] == st, onSelected: (_) => setState(() => it['status'] = st)),
                    ),
                ]),
              ]),
            ),
          ),
        const SizedBox(height: 12),
        TextField(controller: summaryCtl, maxLines: 6, decoration: InputDecoration(labelText: context.t('final_summary'), border: const OutlineInputBorder())),
        const SizedBox(height: 12),
        Row(children: [
          OutlinedButton(onPressed: () => _submit(false), child: Text(context.t('save_version'))),
          const SizedBox(width: 8),
          FilledButton(onPressed: () => _submit(true), child: Text(context.t('finalize'))),
        ]),
      ]),
    );
  }
}
