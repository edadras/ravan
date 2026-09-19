import 'package:flutter/material.dart';

import '../../core/l10n.dart';

/// Two separate, explicit consents shown BEFORE the camera is switched on.
/// Texts come from GET /consents/texts?locale=<ui language>, so they match the patient's language.
class ConsentDialog extends StatefulWidget {
  const ConsentDialog({super.key, required this.texts});

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
      title: Text(context.t('before_start')),
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
                        Text(context.t('version', {'v': t['version']}), style: const TextStyle(fontSize: 11, color: Colors.black38)),
                      ],
                    ),
                  ),
                  controlAffinity: ListTileControlAffinity.leading,
                ),
                const Divider(),
              ],
              Text(context.t('consent_footer'), style: const TextStyle(fontSize: 12)),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, <String>{}), child: Text(context.t('continue_text_only'))),
        FilledButton(onPressed: _granted.contains('video_call') ? () => Navigator.pop(context, _granted) : null, child: Text(context.t('confirm_join'))),
      ],
    );
  }
}
