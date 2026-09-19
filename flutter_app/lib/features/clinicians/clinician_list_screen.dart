import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth_store.dart';
import '../../core/models.dart';

/// Public directory. Only admin-verified clinicians are returned by the API.
class ClinicianListScreen extends StatefulWidget {
  const ClinicianListScreen({super.key});

  @override
  State<ClinicianListScreen> createState() => _ClinicianListScreenState();
}

class _ClinicianListScreenState extends State<ClinicianListScreen> {
  List<Clinician> clinicians = [];
  List<Map<String, dynamic>> specialties = [];
  String? specialty;
  String? mode;
  bool loading = true;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final api = AuthScope.of(context).api;
    setState(() => loading = true);
    specialties = (await api.get('/clinicians/specialties') as List).cast<Map<String, dynamic>>();
    final res = await api.get('/clinicians', query: {if (specialty != null) 'specialty': specialty!, if (mode != null) 'mode': mode!}) as Map<String, dynamic>;
    setState(() {
      clinicians = (res['data'] as List).map((j) => Clinician.fromJson(j as Map<String, dynamic>)).toList();
      loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final fmt = NumberFormat.decimalPattern('fa');
    return Scaffold(
      appBar: AppBar(title: const Text('انتخاب روان‌شناس / روان‌پزشک')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(children: [
            DropdownButton<String?>(
              value: specialty,
              hint: const Text('تخصص'),
              items: [const DropdownMenuItem(value: null, child: Text('همه تخصص‌ها')), ...specialties.map((s) => DropdownMenuItem(value: s['slug'] as String, child: Text(s['name_fa'] as String)))],
              onChanged: (v) { specialty = v; _load(); },
            ),
            const SizedBox(width: 16),
            DropdownButton<String?>(
              value: mode,
              hint: const Text('نوع جلسه'),
              items: const [DropdownMenuItem(value: null, child: Text('همه')), DropdownMenuItem(value: 'text', child: Text('متنی')), DropdownMenuItem(value: 'audio', child: Text('صوتی')), DropdownMenuItem(value: 'video', child: Text('تصویری'))],
              onChanged: (v) { mode = v; _load(); },
            ),
          ]),
        ),
        Expanded(
          child: loading
              ? const Center(child: CircularProgressIndicator())
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  itemCount: clinicians.length,
                  itemBuilder: (_, i) {
                    final c = clinicians[i];
                    return Card(
                      child: ListTile(
                        leading: const CircleAvatar(child: Icon(Icons.psychology)),
                        title: Row(children: [Text(c.name, style: const TextStyle(fontWeight: FontWeight.w700)), const SizedBox(width: 8), const Icon(Icons.verified, size: 16, color: Colors.green), const Text(' تأیید شده', style: TextStyle(fontSize: 11, color: Colors.green))]),
                        subtitle: Text('${c.title ?? ''} · ${c.specialties.join('، ')} · ${c.languages.join('/')} · ${c.modes.map((m) => {'text': 'متن', 'audio': 'صوت', 'video': 'تصویر'}[m]).join('، ')}'),
                        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Text('${fmt.format(c.fee)} ${c.currency == 'IRR' ? 'ریال' : c.currency}', style: const TextStyle(fontWeight: FontWeight.w600)),
                          Text('${c.sessionMinutes} دقیقه', style: const TextStyle(fontSize: 12)),
                        ]),
                        onTap: () => context.go('/clinicians/${c.id}'),
                      ),
                    );
                  },
                ),
        ),
      ]),
    );
  }
}
