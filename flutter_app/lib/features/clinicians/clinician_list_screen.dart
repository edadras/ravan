import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth_store.dart';
import '../../core/l10n.dart';
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
    final fmt = NumberFormat.decimalPattern(context.lang);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('choose_clinician')), actions: const [LanguageSwitcher()]),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(children: [
            DropdownButton<String?>(
              value: specialty,
              hint: Text(context.t('specialty')),
              items: [
                DropdownMenuItem(value: null, child: Text(context.t('all_specialties'))),
                ...specialties.map((s) => DropdownMenuItem(value: s['slug'] as String, child: Text((s['name_${context.lang}'] ?? s['name_en']) as String))),
              ],
              onChanged: (v) { specialty = v; _load(); },
            ),
            const SizedBox(width: 16),
            DropdownButton<String?>(
              value: mode,
              hint: Text(context.t('session_type')),
              items: [
                DropdownMenuItem(value: null, child: Text(context.t('all'))),
                for (final m in const ['text', 'audio', 'video']) DropdownMenuItem(value: m, child: Text(context.t('mode_$m'))),
              ],
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
                        title: Row(children: [
                          Text(c.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(width: 8),
                          const Icon(Icons.verified, size: 16, color: Colors.green),
                          Text(' ${context.t('verified')}', style: const TextStyle(fontSize: 11, color: Colors.green)),
                        ]),
                        subtitle: Text('${c.title ?? ''} · ${c.specialties.map(context.pick).join(', ')} · ${c.languages.map((l) => langNames[l] ?? l).join('/')} · ${c.modes.map((m) => context.t('mode_$m')).join(', ')}'),
                        trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Text('${fmt.format(c.fee)} ${c.currency == 'IRR' ? context.t('currency_irr') : c.currency}', style: const TextStyle(fontWeight: FontWeight.w600)),
                          Text(context.t('minutes', {'n': c.sessionMinutes}), style: const TextStyle(fontSize: 12)),
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
