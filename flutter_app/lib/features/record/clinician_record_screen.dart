import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';
import '../ai/ai_panel.dart';

/// Clinician view of a patient's record: overview, SOAP notes, diagnoses, questionnaires, medications, AI.
class ClinicianRecordScreen extends StatefulWidget {
  const ClinicianRecordScreen({super.key, required this.patientId, this.sessionUuid});
  final int patientId;
  final String? sessionUuid;

  @override
  State<ClinicianRecordScreen> createState() => _ClinicianRecordScreenState();
}

class _ClinicianRecordScreenState extends State<ClinicianRecordScreen> with SingleTickerProviderStateMixin {
  Map<String, dynamic>? record;
  late final TabController tabs = TabController(length: widget.sessionUuid != null ? 6 : 5, vsync: this);
  late ApiClient api;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    api = AuthScope.of(context).api;
    record = await api.get('/records/${widget.patientId}') as Map<String, dynamic>;
    setState(() {});
  }

  Future<void> _post(String path, Map<String, dynamic> body, {bool patch = false}) async {
    try {
      await (patch ? api.patch(path, body) : api.post(path, body));
      await _load();
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (record == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final p = widget.patientId;
    return Scaffold(
      appBar: AppBar(
        title: Text(context.t('record')),
        actions: const [LanguageSwitcher()],
        bottom: TabBar(controller: tabs, isScrollable: true, tabs: [
          Tab(text: context.t('formulation')), Tab(text: context.t('soap_entries')), Tab(text: context.t('diagnoses')), Tab(text: context.t('screenings')), Tab(text: context.t('medications')),
          if (widget.sessionUuid != null) Tab(text: context.t('ai_panel')),
        ]),
      ),
      body: TabBarView(controller: tabs, children: [
        _Overview(record: record!, onSave: (d) => _post('/records/$p/clinical', d, patch: true)),
        _Entries(record: record!, onAdd: (d) => _post('/records/$p/entries', d..['therapy_session_uuid'] = widget.sessionUuid)),
        _Diagnoses(record: record!, api: api, onAdd: (d) => _post('/records/$p/diagnoses', d), onUpdate: (id, st) => _post('/records/$p/diagnoses/$id', {'status': st}, patch: true)),
        _Screenings(record: record!),
        _Medications(record: record!, onAdd: (d) => _post('/records/$p/medications', d)),
        if (widget.sessionUuid != null) AiPanel(sessionUuid: widget.sessionUuid!, patientId: p, onDiagnosisAdded: _load),
      ]),
    );
  }
}

class _Overview extends StatefulWidget {
  const _Overview({required this.record, required this.onSave});
  final Map<String, dynamic> record;
  final Future<void> Function(Map<String, dynamic>) onSave;
  @override
  State<_Overview> createState() => _OverviewState();
}

class _OverviewState extends State<_Overview> {
  late final risk = TextEditingController(text: (widget.record['risk_history'] ?? '') as String);
  late final form = TextEditingController(text: (widget.record['formulation'] ?? '') as String);
  late final plan = TextEditingController(text: (widget.record['treatment_plan'] ?? '') as String);

  @override
  Widget build(BuildContext context) {
    final r = widget.record;
    return ListView(padding: const EdgeInsets.all(16), children: [
      for (final k in const ['chief_complaint', 'history_of_present_illness', 'psychiatric_history', 'medical_history', 'family_history', 'social_history', 'substance_use'])
        if ((r[k] ?? '').toString().isNotEmpty) ListTile(title: Text(context.t({'history_of_present_illness': 'hpi', 'psychiatric_history': 'psych_history'}[k] ?? k)), subtitle: Text(r[k] as String)),
      TextField(controller: risk, maxLines: 3, decoration: InputDecoration(labelText: context.t('risk_history'), border: const OutlineInputBorder())),
      const SizedBox(height: 8),
      TextField(controller: form, maxLines: 6, decoration: InputDecoration(labelText: context.t('formulation'), border: const OutlineInputBorder())),
      const SizedBox(height: 8),
      TextField(controller: plan, maxLines: 4, decoration: InputDecoration(labelText: context.t('treatment_plan'), border: const OutlineInputBorder())),
      const SizedBox(height: 8),
      FilledButton(onPressed: () => widget.onSave({'risk_history': risk.text, 'formulation': form.text, 'treatment_plan': plan.text}), child: Text(context.t('save'))),
    ]);
  }
}

class _Entries extends StatelessWidget {
  const _Entries({required this.record, required this.onAdd});
  final Map<String, dynamic> record;
  final Future<void> Function(Map<String, dynamic>) onAdd;

  @override
  Widget build(BuildContext context) {
    final entries = (record['entries'] as List).cast<Map<String, dynamic>>();
    return ListView(padding: const EdgeInsets.all(16), children: [
      FilledButton.icon(onPressed: () => _dialog(context), icon: const Icon(Icons.add), label: Text(context.t('add_entry'))),
      for (final e in entries)
        Card(child: ListTile(
          leading: Icon(e['is_locked'] == true ? Icons.lock : Icons.edit_note),
          title: Text('${e['type']} · ${'${e['created_at']}'.substring(0, 10)}'),
          subtitle: Text([for (final k in ['subjective', 'objective', 'assessment', 'plan']) if ((e[k] ?? '').toString().isNotEmpty) '${k[0].toUpperCase()}: ${e[k]}'].join('\n')),
        )),
    ]);
  }

  Future<void> _dialog(BuildContext context) async {
    final c = {for (final k in ['subjective', 'objective', 'assessment', 'plan']) k: TextEditingController()};
    var lock = false;
    final ok = await showDialog<bool>(context: context, builder: (_) => StatefulBuilder(builder: (ctx, setS) => AlertDialog(
      title: Text(context.t('add_entry')),
      content: SizedBox(width: 520, child: Column(mainAxisSize: MainAxisSize.min, children: [
        for (final k in c.keys) TextField(controller: c[k], maxLines: 3, decoration: InputDecoration(labelText: context.t(k))),
        CheckboxListTile(value: lock, onChanged: (v) => setS(() => lock = v ?? false), title: Text(context.t('lock_entry'))),
      ])),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(context.t('cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(context.t('save')))],
    )));
    if (ok == true) await onAdd({'type': 'progress', for (final e in c.entries) e.key: e.value.text, 'lock': lock});
  }
}

class _Diagnoses extends StatelessWidget {
  const _Diagnoses({required this.record, required this.api, required this.onAdd, required this.onUpdate});
  final Map<String, dynamic> record;
  final ApiClient api;
  final Future<void> Function(Map<String, dynamic>) onAdd;
  final Future<void> Function(int, String) onUpdate;

  @override
  Widget build(BuildContext context) {
    final dxs = (record['diagnoses'] as List).cast<Map<String, dynamic>>();
    return ListView(padding: const EdgeInsets.all(16), children: [
      FilledButton.icon(onPressed: () => _dialog(context), icon: const Icon(Icons.add), label: Text(context.t('add_diagnosis'))),
      for (final d in dxs)
        Card(child: ListTile(
          title: Text('${d['label']} ${d['code'] != null ? '(${d['code']['code']})' : ''}'),
          subtitle: Text('${context.t('status_${d['status']}')} · ${context.t(d['source'] == 'ai_suggested' ? 'source_ai' : 'source_clinician')}\n${d['evidence'] ?? ''}'),
          trailing: PopupMenuButton<String>(onSelected: (st) => onUpdate(d['id'] as int, st), itemBuilder: (_) => [for (final st in const ['provisional', 'confirmed', 'ruled_out', 'resolved']) PopupMenuItem(value: st, child: Text(context.t('status_$st')))]),
        )),
    ]);
  }

  Future<void> _dialog(BuildContext context) async {
    final q = TextEditingController();
    final ev = TextEditingController();
    List<Map<String, dynamic>> codes = [];
    Map<String, dynamic>? chosen;
    var status = 'provisional';
    final ok = await showDialog<bool>(context: context, builder: (_) => StatefulBuilder(builder: (ctx, setS) => AlertDialog(
      title: Text(context.t('add_diagnosis')),
      content: SizedBox(width: 520, child: Column(mainAxisSize: MainAxisSize.min, children: [
        TextField(controller: q, decoration: InputDecoration(labelText: context.t('search_code')), onChanged: (v) async {
          codes = (await api.get('/records/codes', query: {'q': v}) as List).cast<Map<String, dynamic>>();
          setS(() {});
        }),
        SizedBox(height: 160, child: ListView(children: [for (final c in codes) ListTile(dense: true, selected: chosen == c, title: Text('${c['code']} — ${c['label_${context.lang}'] ?? c['label_en']}'), onTap: () => setS(() => chosen = c))])),
        Wrap(spacing: 6, children: [for (final st in const ['provisional', 'confirmed']) ChoiceChip(label: Text(context.t('status_$st')), selected: status == st, onSelected: (_) => setS(() => status = st))]),
        TextField(controller: ev, maxLines: 3, decoration: InputDecoration(labelText: context.t('evidence'))),
      ])),
      actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(context.t('cancel'))), FilledButton(onPressed: chosen == null ? null : () => Navigator.pop(ctx, true), child: Text(context.t('save')))],
    )));
    if (ok == true && chosen != null) await onAdd({'diagnosis_code_id': chosen!['id'], 'status': status, 'evidence': ev.text});
  }
}

class _Screenings extends StatelessWidget {
  const _Screenings({required this.record});
  final Map<String, dynamic> record;
  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.all(16), children: [
        for (final s in (record['screenings'] as List))
          ListTile(leading: Icon(s['item_flag'] == true ? Icons.flag : Icons.assignment_turned_in, color: s['item_flag'] == true ? Colors.red : null), title: Text((s['instrument'] as String).toUpperCase()), subtitle: Text('${context.t('score')}: ${s['total_score']} · ${context.t('band')}: ${s['severity_band']} · ${'${s['created_at']}'.substring(0, 10)}')),
      ]);
}

class _Medications extends StatelessWidget {
  const _Medications({required this.record, required this.onAdd});
  final Map<String, dynamic> record;
  final Future<void> Function(Map<String, dynamic>) onAdd;
  @override
  Widget build(BuildContext context) => ListView(padding: const EdgeInsets.all(16), children: [
        FilledButton.icon(onPressed: () async {
          final c = {for (final k in ['name', 'dose', 'frequency', 'notes']) k: TextEditingController()};
          final ok = await showDialog<bool>(context: context, builder: (ctx) => AlertDialog(title: Text(context.t('add_medication')), content: Column(mainAxisSize: MainAxisSize.min, children: [for (final k in c.keys) TextField(controller: c[k], decoration: InputDecoration(labelText: context.t(k)))]), actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(context.t('cancel'))), FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(context.t('save')))]));
          if (ok == true) await onAdd({for (final e in c.entries) e.key: e.value.text});
        }, icon: const Icon(Icons.add), label: Text(context.t('add_medication'))),
        for (final m in (record['medications'] as List)) ListTile(title: Text(m['name'] as String), subtitle: Text('${m['dose'] ?? ''} ${m['frequency'] ?? ''} ${m['notes'] ?? ''}')),
      ]);
}
