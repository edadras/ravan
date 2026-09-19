import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';

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
        ..add({'key': 'ai_summary', 'ai_text': report!['ai_draft_summary'] ?? '', 'clinician_text': report!['ai_draft_summary'] ?? '', 'status': 'draft', 'title': 'پیش‌نویس خلاصه (هوش مصنوعی)'});
      for (final c in ((s['strongest_changes'] ?? []) as List).take(10)) {
        items.add({'key': 'change:${c['event_id']}', 'ai_text': '${c['t']} — ${c['observation']['fa']} (فعلی ${c['observed']} در برابر خط پایه ${c['baseline']})', 'clinician_text': '', 'status': 'draft', 'title': 'تغییر قابل توجه'});
      }
      for (final c in ((s['clusters'] ?? []) as List)) {
        items.add({'key': 'cluster:${c['event_id']}', 'ai_text': '${c['t']} — ${c['observation']['fa']}؛ اعضا: ${(c['members'] as List).length}', 'clinician_text': '', 'status': 'draft', 'title': 'خوشه چندوجهی'});
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
    if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(finalize ? 'گزارش نهایی شد' : 'نسخه جدید ذخیره شد')));
  }

  @override
  Widget build(BuildContext context) {
    if (error != null) return Scaffold(appBar: AppBar(title: const Text('گزارش جلسه')), body: Center(child: Text(error!)));
    if (report == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final s = report!['structured'] as Map<String, dynamic>;
    final conv = (s['conversation'] ?? {}) as Map<String, dynamic>;
    String mmss(num ms) => '${(ms ~/ 60000).toString().padLeft(2, '0')}:${((ms ~/ 1000) % 60).toString().padLeft(2, '0')}';
    return Scaffold(
      appBar: AppBar(title: const Text('گزارش جلسه — پیش‌نویس برای بازبینی درمانگر')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('مدت: ${mmss(s['duration_ms'] ?? 0)}'),
              Text('گفتار درمانگر: ${mmss(conv['clinician_speaking_ms'] ?? 0)}   گفتار بیمار: ${mmss(conv['patient_speaking_ms'] ?? 0)}   سکوت: ${mmss(conv['silence_ms'] ?? 0)}'),
              Text('تغییرات قابل توجه: ${s['behavioral_observations']?['notable_change_events'] ?? 0}   خوشه‌ها: ${s['behavioral_observations']?['clusters'] ?? 0}'),
              Text('کیفیت خط پایه: ${(s['baseline']?['quality_fraction'] ?? 0)}   پوشش: ${s['baseline']?['coverage_s'] ?? 0} ثانیه', style: const TextStyle(color: Colors.black54)),
              const SizedBox(height: 6),
              Text((s['disclaimer']?['fa'] ?? '') as String, style: const TextStyle(fontSize: 12, color: Colors.deepOrange)),
            ]),
          ),
        ),
        const SizedBox(height: 12),
        for (final it in items)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(it['title'] as String, style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                Text(it['ai_text'] as String, style: const TextStyle(fontSize: 13)),
                if (it['status'] == 'edited')
                  TextField(
                    decoration: const InputDecoration(labelText: 'متن شما', border: OutlineInputBorder()),
                    maxLines: 3,
                    controller: TextEditingController(text: it['clinician_text'] as String),
                    onChanged: (v) => it['clinician_text'] = v,
                  ),
                Row(children: [
                  for (final st in const ['accepted', 'edited', 'rejected'])
                    Padding(
                      padding: const EdgeInsets.only(left: 6),
                      child: ChoiceChip(
                        label: Text(const {'accepted': 'تأیید', 'edited': 'ویرایش', 'rejected': 'رد'}[st]!),
                        selected: it['status'] == st,
                        onSelected: (_) => setState(() => it['status'] = st),
                      ),
                    ),
                ]),
              ]),
            ),
          ),
        const SizedBox(height: 12),
        TextField(controller: summaryCtl, maxLines: 6, decoration: const InputDecoration(labelText: 'خلاصه نهایی درمانگر (فقط این متن به پرونده می‌رود)', border: OutlineInputBorder())),
        const SizedBox(height: 12),
        Row(children: [
          OutlinedButton(onPressed: () => _submit(false), child: const Text('ذخیره نسخه')),
          const SizedBox(width: 8),
          FilledButton(onPressed: () => _submit(true), child: const Text('نهایی کردن گزارش')),
        ]),
      ]),
    );
  }
}
