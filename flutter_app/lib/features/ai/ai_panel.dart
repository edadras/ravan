import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Clinical decision support: formulation + differential hypotheses, Q&A response analysis, chat.
/// Every result is a stored suggestion; "Add as provisional diagnosis" writes to the record only on the clinician's click.
class AiPanel extends StatefulWidget {
  const AiPanel({super.key, required this.sessionUuid, required this.patientId, this.onDiagnosisAdded});
  final String sessionUuid;
  final int patientId;
  final VoidCallback? onDiagnosisAdded;

  @override
  State<AiPanel> createState() => _AiPanelState();
}

class _AiPanelState extends State<AiPanel> {
  Map<String, dynamic>? formulation;
  Map<String, dynamic>? qa;
  final chat = <Map<String, dynamic>>[];
  final question = TextEditingController();
  bool busy = false;

  ApiClient get api => AuthScope.of(context).api;

  Future<void> _run(Future<void> Function() f) async {
    setState(() => busy = true);
    try {
      await f();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  Future<void> _addProvisional(Map<String, dynamic> h) async {
    final codes = h['icd11'] != null ? (await api.get('/records/codes', query: {'q': h['icd11'] as String}) as List).cast<Map<String, dynamic>>() : <Map<String, dynamic>>[];
    await api.post('/records/${widget.patientId}/diagnoses', {
      if (codes.isNotEmpty) 'diagnosis_code_id': codes.first['id'],
      'label': h['label'], 'status': 'provisional', 'ai_suggestion_uuid': formulation!['uuid'],
      'evidence': '${context.t('evidence_for')}: ${(h['evidence_for'] as List).join('; ')}\n${context.t('evidence_against')}: ${(h['evidence_against'] as List).join('; ')}',
    });
    await api.post('/ai/suggestions/${formulation!['uuid']}/review', {'status': 'accepted'});
    widget.onDiagnosisAdded?.call();
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('saved'))));
  }

  @override
  Widget build(BuildContext context) {
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(context.t('ai_disclaimer'), style: const TextStyle(fontSize: 12, color: Colors.deepOrange)),
      const SizedBox(height: 8),
      // ------------------------------------------------ formulation
      Row(children: [
        Text(context.t('ai_formulation'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        const Spacer(),
        FilledButton(onPressed: busy ? null : () => _run(() async => setState(() {})).then((_) => _run(() async { formulation = await api.post('/sessions/${widget.sessionUuid}/ai/formulation') as Map<String, dynamic>; })), child: Text(context.t('ai_generate'))),
      ]),
      if (formulation != null) ..._formulation(formulation!['output'] as Map<String, dynamic>, formulation!['provider'] as String),
      const Divider(height: 32),
      // ------------------------------------------------ Q&A
      Row(children: [
        Text(context.t('ai_qa'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        const Spacer(),
        FilledButton(onPressed: busy ? null : () => _run(() async { qa = await api.post('/sessions/${widget.sessionUuid}/ai/qa') as Map<String, dynamic>; }), child: Text(context.t('ai_generate'))),
      ]),
      if (qa != null) ..._qa(qa!['output'] as Map<String, dynamic>),
      const Divider(height: 32),
      // ------------------------------------------------ chat
      Text(context.t('ai_chat'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
      for (final m in chat)
        Align(alignment: m['role'] == 'user' ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart, child: Card(color: m['role'] == 'user' ? Colors.indigo.shade50 : null, child: Padding(padding: const EdgeInsets.all(8), child: Text(m['content'] as String)))),
      Row(children: [
        Expanded(child: TextField(controller: question, decoration: InputDecoration(hintText: context.t('ai_ask_hint'), border: const OutlineInputBorder(), isDense: true))),
        const SizedBox(width: 8),
        FilledButton(onPressed: busy ? null : () => _run(() async {
          final q = question.text.trim();
          if (q.isEmpty) return;
          setState(() => chat.add({'role': 'user', 'content': q}));
          question.clear();
          final res = await api.post('/sessions/${widget.sessionUuid}/ai/chat', {'question': q, 'history': chat.take(chat.length - 1).toList()}) as Map<String, dynamic>;
          final out = res['output'] as Map<String, dynamic>;
          final cites = ((out['citations'] ?? []) as List).map((c) => '[${c['t']}] ${c['text']}').join('\n');
          setState(() => chat.add({'role': 'assistant', 'content': '${out['answer']}${cites.isNotEmpty ? '\n$cites' : ''}'}));
        }), child: const Icon(Icons.send)),
      ]),
    ]);
  }

  List<Widget> _formulation(Map<String, dynamic> o, String provider) => [
        if (provider == 'NullProvider') Text(context.t('no_llm_note'), style: const TextStyle(fontSize: 12, color: Colors.black54)),
        Text(o['summary']?.toString() ?? ''),
        for (final k in ((o['key_observations'] ?? []) as List)) ListTile(dense: true, leading: Text(k['t']?.toString() ?? ''), title: Text(k['observation']?.toString() ?? ''), subtitle: Text('${k['evidence'] ?? ''} · ${k['source'] ?? ''}')),
        for (final h in ((o['differential'] ?? []) as List).cast<Map<String, dynamic>>())
          Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${context.t('ai_hypothesis')}: ${h['label']} ${h['icd11'] != null ? '(${h['icd11']})' : ''} — ${context.t('confidence_level')}: ${h['confidence']}', style: const TextStyle(fontWeight: FontWeight.w700)),
            _list(context.t('evidence_for'), h['evidence_for']), _list(context.t('evidence_against'), h['evidence_against']),
            _list(context.t('would_confirm'), h['would_confirm']), _list(context.t('would_rule_out'), h['would_rule_out']),
            _list(context.t('suggested_questions'), h['suggested_questions']), _list(context.t('suggested_instruments'), h['suggested_instruments']),
            Align(alignment: AlignmentDirectional.centerEnd, child: OutlinedButton.icon(onPressed: () => _addProvisional(h), icon: const Icon(Icons.playlist_add), label: Text(context.t('add_as_provisional')))),
          ]))),
        for (final r in ((o['risk_relevant_statements'] ?? []) as List)) ListTile(leading: const Icon(Icons.warning, color: Colors.red), title: Text('${r['t']}  "${r['quote']}"'), subtitle: Text(r['note']?.toString() ?? '')),
        _list(context.t('plan'), o['suggested_next_steps']),
        Text(o['disclaimer']?.toString() ?? '', style: const TextStyle(fontSize: 11, color: Colors.black45)),
        Row(children: [
          TextButton(onPressed: () => api.post('/ai/suggestions/${formulation!['uuid']}/review', {'status': 'accepted'}), child: Text(context.t('accept'))),
          TextButton(onPressed: () => api.post('/ai/suggestions/${formulation!['uuid']}/review', {'status': 'rejected'}), child: Text(context.t('reject'))),
        ]),
      ];

  List<Widget> _qa(Map<String, dynamic> o) => [
        for (final p in ((o['pairs'] ?? []) as List).cast<Map<String, dynamic>>())
          Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('${p['t']}  ${p['question']}', style: const TextStyle(fontWeight: FontWeight.w700)),
            Text('"${p['answer']}"', style: const TextStyle(color: Colors.black87)),
            Text('${context.t('how_answered')}: ${p['how_answered'] ?? ''}'),
            Wrap(spacing: 4, children: [for (final d in ((p['descriptors'] ?? []) as List)) Chip(label: Text(d.toString(), style: const TextStyle(fontSize: 11)), visualDensity: VisualDensity.compact)]),
            if (((p['co_occurring_changes'] ?? []) as List).isNotEmpty) Text('${context.t('co_occurring')}: ${(p['co_occurring_changes'] as List).join(', ')}', style: const TextStyle(fontSize: 12)),
            _list(context.t('interpretations'), p['possible_interpretations']),
            if ((p['follow_up_question'] ?? '').toString().isNotEmpty) Text('→ ${p['follow_up_question']}', style: const TextStyle(color: Colors.indigo)),
          ]))),
        _list(context.t('patterns'), o['patterns']),
        Text(o['disclaimer']?.toString() ?? '', style: const TextStyle(fontSize: 11, color: Colors.black45)),
      ];

  Widget _list(String title, dynamic items) {
    final l = (items as List?) ?? const [];
    if (l.isEmpty) return const SizedBox.shrink();
    return Padding(padding: const EdgeInsets.only(top: 4), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(title, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)), for (final i in l) Text('• $i', style: const TextStyle(fontSize: 13))]));
  }
}
