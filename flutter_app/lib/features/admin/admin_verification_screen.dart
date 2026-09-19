import 'package:flutter/material.dart';

import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Admin: review clinician credentials and approve / reject / suspend.
class AdminVerificationScreen extends StatefulWidget {
  const AdminVerificationScreen({super.key});

  @override
  State<AdminVerificationScreen> createState() => _AdminVerificationScreenState();
}

class _AdminVerificationScreenState extends State<AdminVerificationScreen> {
  List<Map<String, dynamic>> profiles = [];
  String status = 'pending';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final res = await AuthScope.of(context).api.get('/admin/clinicians', query: {'status': status}) as Map<String, dynamic>;
    setState(() => profiles = (res['data'] as List).cast<Map<String, dynamic>>());
  }

  Future<void> _decide(Map<String, dynamic> p, String decision) async {
    final notes = TextEditingController();
    final checks = {'license_valid': false, 'identity_matched': false, 'degree_verified': false};
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => StatefulBuilder(
        builder: (context, setS) => AlertDialog(
          title: Text(context.t('decision_title', {'d': context.t(decision == 'approved' ? 'approve' : decision == 'rejected' ? 'reject' : 'suspend'), 'name': p['user']['name']})),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            for (final k in checks.keys)
              CheckboxListTile(value: checks[k], onChanged: (v) => setS(() => checks[k] = v ?? false), title: Text(context.t(k))),
            TextField(controller: notes, decoration: InputDecoration(labelText: context.t('notes')), maxLines: 3),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: Text(context.t('cancel'))), FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(context.t('submit')))],
        ),
      ),
    );
    if (ok == true && mounted) {
      await AuthScope.of(context).api.post('/admin/clinicians/${p['id']}/decision', {'decision': decision, 'notes': notes.text, 'checked_items': checks});
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('admin_title')), actions: [
        DropdownButton<String>(
          value: status,
          items: [
            DropdownMenuItem(value: 'pending', child: Text(context.t('pending'))),
            DropdownMenuItem(value: 'approved', child: Text(context.t('approved'))),
            DropdownMenuItem(value: 'rejected', child: Text(context.t('rejected_s'))),
            DropdownMenuItem(value: 'suspended', child: Text(context.t('suspended'))),
          ],
          onChanged: (v) { status = v!; _load(); },
        ),
        const LanguageSwitcher(),
        const SizedBox(width: 8),
      ]),
      body: ListView.builder(
        padding: const EdgeInsets.all(12),
        itemCount: profiles.length,
        itemBuilder: (_, i) {
          final p = profiles[i];
          final docs = (p['documents'] as List?) ?? [];
          return Card(
            child: ListTile(
              title: Text('${p['user']['name']} — ${p['title'] ?? ''}'),
              subtitle: Text('${p['license_authority'] ?? ''} · ${context.t('license_no')}: ${p['license_number'] ?? '—'} · ${context.t('documents')}: ${docs.length} · ${p['user']['email']}'),
              trailing: Wrap(spacing: 6, children: [
                FilledButton(onPressed: () => _decide(p, 'approved'), child: Text(context.t('approve'))),
                OutlinedButton(onPressed: () => _decide(p, 'rejected'), child: Text(context.t('reject'))),
                TextButton(onPressed: () => _decide(p, 'suspended'), child: Text(context.t('suspend'))),
              ]),
            ),
          );
        },
      ),
    );
  }
}
