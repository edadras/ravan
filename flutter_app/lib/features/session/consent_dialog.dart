import 'package:flutter/material.dart';

/// Two separate, explicit consents shown BEFORE the camera is switched on.
/// The patient can grant the call consent alone; analysis is opt-in and reversible mid-session.
class ConsentDialog extends StatefulWidget {
  const ConsentDialog({super.key, required this.texts});

  /// From GET /consents/texts
  final List<Map<String, dynamic>> texts;

  static Future<Set<String>?> show(BuildContext context, List<Map<String, dynamic>> texts) =>
      showDialog<Set<String>>(context: context, barrierDismissible: false, builder: (_) => ConsentDialog(texts: texts));

  @override
  State<ConsentDialog> createState() => _ConsentDialogState();
}

class _ConsentDialogState extends State<ConsentDialog> {
  final Set<String> _granted = {};

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('پیش از شروع جلسه'),
      content: SizedBox(
        width: 520,
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final t in widget.texts) ...[
                CheckboxListTile(
                  value: _granted.contains(t['type']),
                  onChanged: (v) => setState(() => v == true ? _granted.add(t['type'] as String) : _granted.remove(t['type'])),
                  title: Text(t['title'] as String, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        for (final b in (t['bullet_points'] as List)) Text('• $b', style: const TextStyle(fontSize: 13)),
                        const SizedBox(height: 4),
                        Text(t['body'] as String, style: const TextStyle(fontSize: 12, color: Colors.black54)),
                        Text('نسخه ${t['version']}', style: const TextStyle(fontSize: 11, color: Colors.black38)),
                      ],
                    ),
                  ),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
                const Divider(),
              ],
              const Text('تحلیل رفتاری فقط اعداد توصیفی را استخراج می‌کند، فقط برای درمانگر و فقط به‌عنوان مشاهده نمایش داده می‌شود و هر لحظه قابل توقف است.',
                  style: TextStyle(fontSize: 12)),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, <String>{}), child: const Text('ادامه فقط با متن')),
        FilledButton(onPressed: _granted.contains('video_call') ? () => Navigator.pop(context, _granted) : null, child: const Text('تأیید و ورود به جلسه')),
      ],
    );
  }
}
