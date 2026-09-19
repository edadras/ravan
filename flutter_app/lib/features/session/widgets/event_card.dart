import 'package:flutter/material.dart';

import '../../../core/models.dart';

const tierColors = {
  'quality': Colors.blueGrey,
  'observation': Colors.grey,
  'change': Color(0xFF3B5BDB),
  'cluster': Color(0xFF7C3AED),
  'content': Colors.teal,
  'medical': Colors.orange,
  'safety': Colors.red,
};

const tierLabelsFa = {
  'quality': 'کیفیت / فنی',
  'observation': 'مشاهده',
  'change': 'تغییر نسبت به خط پایه',
  'cluster': 'خوشه چندوجهی',
  'content': 'محتوای کلامی',
  'medical': 'احتمال توضیح جسمی/پزشکی',
  'safety': 'محتوای مرتبط با ایمنی — بررسی فوری',
};

/// One observation on the clinician's timeline. Shows WHAT changed, HOW MUCH, versus WHICH baseline,
/// at WHAT quality, then the benign contexts first, then the clinical rationale, then actions.
class EventCard extends StatelessWidget {
  const EventCard({super.key, required this.event, required this.onReview, this.onOpenTranscript});

  final BehaviorEvent event;
  final void Function(String status, {String? note, String? selectedContext}) onReview;
  final VoidCallback? onOpenTranscript;

  String _fmt(double? v) => v == null ? '—' : (v.abs() >= 100 ? v.toStringAsFixed(0) : v.toStringAsFixed(2));

  @override
  Widget build(BuildContext context) {
    final color = tierColors[event.tier] ?? Colors.grey;
    final q = event.quality.entries.where((e) => e.value is num && e.key.endsWith('_quality')).map((e) => '${e.key.replaceAll('_quality', '')}=${(e.value as num).toStringAsFixed(2)}').join('، ');
    final question = event.context['preceding_question_text'] as String?;
    final after = event.context['seconds_after_question'];
    final members = (event.context['member_signals'] as List?)?.cast<String>() ?? const [];
    return Card(
      margin: const EdgeInsets.symmetric(vertical: 6),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: color.withOpacity(0.5))),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Text(event.timeLabel, style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()], fontWeight: FontWeight.w700)),
            const SizedBox(width: 8),
            Chip(label: Text(tierLabelsFa[event.tier] ?? event.tier, style: const TextStyle(fontSize: 11, color: Colors.white)), backgroundColor: color, padding: EdgeInsets.zero, visualDensity: VisualDensity.compact),
            const Spacer(),
            Text('اطمینان ${(event.confidence * 100).round()}٪', style: const TextStyle(fontSize: 12, color: Colors.black54)),
          ]),
          const SizedBox(height: 6),
          Text(event.observationFa, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600)),
          if (event.observedValue != null || event.baselineValue != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                'خط پایه: ${_fmt(event.baselineValue)} ${event.unit ?? ''}   فعلی: ${_fmt(event.observedValue)} ${event.unit ?? ''}'
                '${event.deltaRatio != null ? '   (×${event.deltaRatio!.toStringAsFixed(1)})' : ''}'
                '${event.zScore != null ? '   z=${event.zScore!.toStringAsFixed(1)}' : ''}',
                style: const TextStyle(fontSize: 13, fontFeatures: [FontFeature.tabularFigures()]),
              ),
            ),
          if (q.isNotEmpty) Text('کیفیت تشخیص: $q', style: const TextStyle(fontSize: 12, color: Colors.black54)),
          if (question != null)
            Padding(
              padding: const EdgeInsets.only(top: 6),
              child: Text('حدود ${after ?? '?'} ثانیه پس از سؤال: «$question»', style: const TextStyle(fontSize: 13, color: Colors.indigo)),
            ),
          if (members.isNotEmpty) Text('اعضای خوشه: ${members.join('، ')}', style: const TextStyle(fontSize: 12)),
          if (event.transcriptText != null)
            Padding(padding: const EdgeInsets.only(top: 4), child: Text('گفته بیمار: «${event.transcriptText}»', style: const TextStyle(fontSize: 13))),
          const SizedBox(height: 8),
          const Text('زمینه‌های محتمل (نه نتیجه‌گیری):', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
          Wrap(spacing: 6, runSpacing: 4, children: [
            for (final c in event.possibleContexts)
              ActionChip(label: Text(c.fa, style: const TextStyle(fontSize: 12)), onPressed: () => onReview('noted', selectedContext: c.key)),
          ]),
          if (event.clinicalRationaleFa != null)
            ExpansionTile(
              tilePadding: EdgeInsets.zero,
              title: const Text('چرا این مشاهده نمایش داده می‌شود؟', style: TextStyle(fontSize: 13)),
              children: [
                Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(event.clinicalRationaleFa!, style: const TextStyle(fontSize: 13))),
                if (event.clinicalNoteFa != null) Text('توجه: ${event.clinicalNoteFa}', style: const TextStyle(fontSize: 12, color: Colors.deepOrange)),
              ],
            ),
          Row(children: [
            FilledButton.tonal(onPressed: event.clinicianStatus == 'relevant' ? null : () => onReview('relevant'), child: const Text('مرتبط است')),
            const SizedBox(width: 6),
            OutlinedButton(onPressed: event.clinicianStatus == 'dismissed' ? null : () => onReview('dismissed'), child: const Text('رد')),
            const SizedBox(width: 6),
            TextButton(onPressed: () => _note(context), child: const Text('یادداشت')),
            if (onOpenTranscript != null) TextButton(onPressed: onOpenTranscript, child: const Text('متن این لحظه')),
            const Spacer(),
            if (event.clinicianStatus != 'unreviewed') Text(event.clinicianStatus, style: const TextStyle(fontSize: 11, color: Colors.black45)),
          ]),
        ]),
      ),
    );
  }

  Future<void> _note(BuildContext context) async {
    final ctl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('یادداشت درمانگر'),
        content: TextField(controller: ctl, maxLines: 4, autofocus: true),
        actions: [TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('انصراف')), FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('ذخیره'))],
      ),
    );
    if (ok == true && ctl.text.trim().isNotEmpty) onReview('noted', note: ctl.text.trim());
  }
}
