import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Runs a standardised questionnaire (PHQ-9, GAD-7, ...) and submits it for server-side scoring.
class ScreeningScreen extends StatefulWidget {
  const ScreeningScreen({super.key, required this.patientId, required this.instrument, this.sessionUuid});
  final int patientId;
  final String instrument;
  final String? sessionUuid;

  @override
  State<ScreeningScreen> createState() => _ScreeningScreenState();
}

class _ScreeningScreenState extends State<ScreeningScreen> {
  List<String> questions = [];
  int max = 3;
  List<int?> answers = [];
  Map<String, dynamic>? result;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final list = (await AuthScope.of(context).api.get('/records/instruments') as List).cast<Map<String, dynamic>>();
    final me = list.firstWhere((i) => i['id'] == widget.instrument);
    setState(() {
      questions = (me['questions'] as List).cast<String>();
      max = me['max'] as int;
      answers = List.filled(questions.length, null);
    });
  }

  Future<void> _submit() async {
    try {
      final res = await AuthScope.of(context).api.post('/records/${widget.patientId}/screenings', {
        'instrument': widget.instrument, 'answers': answers.map((a) => a ?? 0).toList(), if (widget.sessionUuid != null) 'therapy_session_uuid': widget.sessionUuid,
      }) as Map<String, dynamic>;
      setState(() => result = res);
    } on ApiException catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final done = answers.isNotEmpty && answers.every((a) => a != null);
    return Scaffold(
      appBar: AppBar(title: Text(widget.instrument.toUpperCase()), actions: const [LanguageSwitcher()]),
      body: result != null
          ? Center(child: Card(child: Padding(padding: const EdgeInsets.all(24), child: Column(mainAxisSize: MainAxisSize.min, children: [
              Text('${context.t('score')}: ${result!['total_score']}', style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
              Text('${context.t('band')}: ${result!['severity_band']}'),
              const SizedBox(height: 12),
              FilledButton(onPressed: () => Navigator.of(context).pop(true), child: Text(context.t('ok'))),
            ]))))
          : ListView(padding: const EdgeInsets.all(16), children: [
              Text(context.t('answer_scale_0_3'), style: const TextStyle(color: Colors.black54, fontSize: 12)),
              for (var i = 0; i < questions.length; i++)
                Card(child: Padding(padding: const EdgeInsets.all(12), child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${i + 1}. ${questions[i]}'),
                  Wrap(spacing: 6, children: [for (var v = 0; v <= max; v++) ChoiceChip(label: Text('$v'), selected: answers[i] == v, onSelected: (_) => setState(() => answers[i] = v))]),
                ]))),
              FilledButton(onPressed: done ? _submit : null, child: Text(context.t('submit'))),
            ]),
    );
  }
}
