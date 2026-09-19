import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/models.dart';

class ClinicianDetailScreen extends StatefulWidget {
  const ClinicianDetailScreen({super.key, required this.id});
  final int id;

  @override
  State<ClinicianDetailScreen> createState() => _ClinicianDetailScreenState();
}

class _ClinicianDetailScreenState extends State<ClinicianDetailScreen> {
  Clinician? c;
  List<Map<String, dynamic>> slots = [];
  String mode = 'video';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final api = AuthScope.of(context).api;
    c = Clinician.fromJson(await api.get('/clinicians/${widget.id}') as Map<String, dynamic>);
    slots = ((await api.get('/clinicians/${widget.id}/availability', query: {'days': '14'}) as Map)['slots'] as List).cast<Map<String, dynamic>>();
    if (!c!.modes.contains(mode) && c!.modes.isNotEmpty) mode = c!.modes.first;
    setState(() {});
  }

  Future<void> _book(String startsAt) async {
    final auth = AuthScope.of(context);
    if (!auth.isLoggedIn) { context.go('/login'); return; }
    try {
      final appt = await auth.api.post('/appointments', {'clinician_profile_id': widget.id, 'starts_at': startsAt, 'mode': mode}) as Map<String, dynamic>;
      if (mounted) {
        await showDialog(context: context, builder: (_) => AlertDialog(title: const Text('نوبت ثبت شد'), content: Text('زمان: ${DateFormat('yyyy/MM/dd HH:mm').format(DateTime.parse(startsAt).toLocal())}\nپس از تأیید درمانگر، از صفحه اصلی وارد جلسه شوید.'), actions: [FilledButton(onPressed: () => Navigator.pop(context), child: const Text('باشه'))]));
        if (mounted) context.go('/');
      }
      debugPrint('appointment ${appt['uuid']}');
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (c == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    return Scaffold(
      appBar: AppBar(title: Text(c!.name)),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Text(c!.title ?? '', style: const TextStyle(fontSize: 16, color: Colors.black54)),
        const SizedBox(height: 8),
        Text(c!.bio ?? ''),
        const SizedBox(height: 8),
        Wrap(spacing: 6, children: [for (final s in c!.specialties) Chip(label: Text(s))]),
        const Divider(height: 32),
        const Text('نوع جلسه', style: TextStyle(fontWeight: FontWeight.w700)),
        Wrap(spacing: 8, children: [
          for (final m in c!.modes)
            ChoiceChip(label: Text({'text': 'متنی', 'audio': 'صوتی', 'video': 'تصویری'}[m]!), selected: mode == m, onSelected: (_) => setState(() => mode = m)),
        ]),
        const SizedBox(height: 16),
        const Text('زمان‌های آزاد (۱۴ روز آینده)', style: TextStyle(fontWeight: FontWeight.w700)),
        if (slots.isEmpty) const Padding(padding: EdgeInsets.all(12), child: Text('زمان آزادی یافت نشد.')),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final s in slots.take(60))
            OutlinedButton(onPressed: () => _book(s['starts_at'] as String), child: Text(DateFormat('EEE d MMM HH:mm', 'fa').format(DateTime.parse(s['starts_at'] as String).toLocal()))),
        ]),
      ]),
    );
  }
}
