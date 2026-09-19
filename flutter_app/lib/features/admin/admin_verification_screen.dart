import 'package:flutter/material.dart';

import '../../core/auth_store.dart';

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
          title: Text('تصمیم: $decision — ${p['user']['name']}'),
          content: Column(mainAxisSize: MainAxisSize.min, children: [
            for (final k in checks.keys)
              CheckboxListTile(value: checks[k], onChanged: (v) => setS(() => checks[k] = v ?? false), title: Text({'license_valid': 'مجوز معتبر و فعال', 'identity_matched': 'هویت با مدارک تطابق دارد', 'degree_verified': 'مدرک تحصیلی تأیید شد'}[k]!)),
            TextField(controller: notes, decoration: const InputDecoration(labelText: 'یادداشت'), maxLines: 3),
          ]),
          actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('انصراف')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('ثبت'))],
        ),
      ),
    );
    if (ok == true) {
      await AuthScope.of(context).api.post('/admin/clinicians/${p['id']}/decision', {'decision': decision, 'notes': notes.text, 'checked_items': checks});
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('احراز هویت درمانگران'), actions: [
        DropdownButton<String>(value: status, items: const [DropdownMenuItem(value: 'pending', child: Text('در انتظار')), DropdownMenuItem(value: 'approved', child: Text('تأیید شده')), DropdownMenuItem(value: 'rejected', child: Text('رد شده')), DropdownMenuItem(value: 'suspended', child: Text('معلق'))], onChanged: (v) { status = v!; _load(); }),
        const SizedBox(width: 16),
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
              subtitle: Text('${p['license_authority'] ?? ''} · شماره مجوز: ${p['license_number'] ?? '—'} · مدارک: ${docs.length} · ${p['user']['email']}'),
              trailing: Wrap(spacing: 6, children: [
                FilledButton(onPressed: () => _decide(p, 'approved'), child: const Text('تأیید')),
                OutlinedButton(onPressed: () => _decide(p, 'rejected'), child: const Text('رد')),
                TextButton(onPressed: () => _decide(p, 'suspended'), child: const Text('تعلیق')),
              ]),
            ),
          );
        },
      ),
    );
  }
}
