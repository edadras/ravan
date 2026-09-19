import 'package:flutter/material.dart';

import '../../core/auth_store.dart';
import '../../core/l10n.dart';
import 'screening_screen.dart';

/// The patient's own record: intake sections they can edit, confirmed diagnoses, questionnaires.
class PatientRecordScreen extends StatefulWidget {
  const PatientRecordScreen({super.key});

  @override
  State<PatientRecordScreen> createState() => _PatientRecordScreenState();
}

class _PatientRecordScreenState extends State<PatientRecordScreen> {
  Map<String, dynamic>? record;
  final fields = ['chief_complaint', 'history_of_present_illness', 'medical_history', 'family_history', 'social_history', 'substance_use'];
  final labels = {'chief_complaint': 'chief_complaint', 'history_of_present_illness': 'hpi', 'medical_history': 'medical_history', 'family_history': 'family_history', 'social_history': 'social_history', 'substance_use': 'substance_use'};
  final ctl = <String, TextEditingController>{};
  List<Map<String, dynamic>> instruments = [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final auth = AuthScope.of(context);
    record = await auth.api.get('/records/${auth.user!.id}') as Map<String, dynamic>;
    instruments = (await auth.api.get('/records/instruments') as List).cast<Map<String, dynamic>>();
    for (final f in fields) {
      ctl[f] = TextEditingController(text: (record![f] ?? '') as String);
    }
    setState(() {});
  }

  Future<void> _save() async {
    final auth = AuthScope.of(context);
    await auth.api.patch('/records/${auth.user!.id}/intake', {for (final f in fields) f: ctl[f]!.text});
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.t('saved'))));
  }

  @override
  Widget build(BuildContext context) {
    if (record == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final auth = AuthScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('my_record')), actions: const [LanguageSwitcher()]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(context.t('intake'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        for (final f in fields) Padding(padding: const EdgeInsets.symmetric(vertical: 4), child: TextField(controller: ctl[f], maxLines: 3, decoration: InputDecoration(labelText: context.t(labels[f]!), border: const OutlineInputBorder()))),
        FilledButton(onPressed: _save, child: Text(context.t('save'))),
        const Divider(height: 32),
        Text(context.t('diagnoses'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        Text(context.t('confirmed_only_note'), style: const TextStyle(fontSize: 12, color: Colors.black54)),
        for (final d in (record!['diagnoses'] as List)) ListTile(leading: const Icon(Icons.verified_outlined), title: Text(d['label'] as String), subtitle: Text('${d['code'] ?? ''} · ${d['confirmed_at'] ?? ''}')),
        const Divider(height: 32),
        Text(context.t('screenings'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        Wrap(spacing: 8, children: [
          for (final i in instruments)
            OutlinedButton(
              onPressed: () async {
                final ok = await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ScreeningScreen(patientId: auth.user!.id, instrument: i['id'] as String)));
                if (ok == true) _load();
              },
              child: Text('${context.t('take_screening')}: ${(i['id'] as String).toUpperCase()}'),
            ),
        ]),
        for (final s in (record!['screenings'] as List))
          ListTile(title: Text((s['instrument'] as String).toUpperCase()), subtitle: Text('${context.t('score')}: ${s['total_score']} · ${context.t('band')}: ${s['severity_band']}'), trailing: Text('${s['created_at']}'.substring(0, 10))),
        const Divider(height: 32),
        Text(context.t('medications'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        for (final m in (record!['medications'] as List)) ListTile(title: Text(m['name'] as String), subtitle: Text('${m['dose'] ?? ''} ${m['frequency'] ?? ''}')),
      ]),
    );
  }
}
