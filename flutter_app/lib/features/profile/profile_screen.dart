import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final name = TextEditingController();
  final phone = TextEditingController();
  final current = TextEditingController();
  final newPw = TextEditingController();
  final code = TextEditingController();
  List<Map<String, dynamic>> notifications = [];
  String? msg;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final auth = AuthScope.of(context);
      name.text = auth.user?.name ?? '';
      _loadNotifications();
    });
  }

  Future<void> _loadNotifications() async {
    final res = await AuthScope.of(context).api.get('/notifications') as List;
    setState(() => notifications = res.cast<Map<String, dynamic>>());
  }

  Future<void> _run(Future<void> Function() f, String okKey) async {
    try {
      await f();
      setState(() => msg = context.t(okKey));
    } on ApiException catch (e) {
      setState(() => msg = e.errors?.values.first?.first?.toString() ?? e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = AuthScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(context.t('profile')), actions: const [LanguageSwitcher()]),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          TextField(controller: name, decoration: InputDecoration(labelText: context.t('name'))),
          TextField(controller: phone, decoration: InputDecoration(labelText: context.t('phone'))),
          const SizedBox(height: 8),
          FilledButton(onPressed: () => _run(() async {
            await auth.api.patch('/auth/me', {'name': name.text.trim(), if (phone.text.trim().isNotEmpty) 'phone': phone.text.trim(), 'locale': context.lang});
            await auth.restore();
          }, 'saved'), child: Text(context.t('save'))),
        ]))),
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.t('change_password'), style: const TextStyle(fontWeight: FontWeight.w700)),
          TextField(controller: current, decoration: InputDecoration(labelText: context.t('current_password')), obscureText: true),
          TextField(controller: newPw, decoration: InputDecoration(labelText: context.t('new_password')), obscureText: true),
          const SizedBox(height: 8),
          FilledButton(onPressed: () => _run(() => auth.api.post('/auth/password/change', {'current_password': current.text, 'password': newPw.text}), 'saved'), child: Text(context.t('change_password'))),
        ]))),
        Card(child: Padding(padding: const EdgeInsets.all(16), child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(context.t('verify_email'), style: const TextStyle(fontWeight: FontWeight.w700)),
          Row(children: [
            Expanded(child: TextField(controller: code, decoration: InputDecoration(labelText: context.t('code')))),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: () => _run(() => auth.api.post('/auth/code/request', {'target': auth.user?.email, 'purpose': 'verify'}), 'code_sent'), child: Text(context.t('send_code'))),
            const SizedBox(width: 8),
            FilledButton(onPressed: () => _run(() => auth.api.post('/auth/verify', {'code': code.text.trim(), 'channel': 'email'}), 'verified_ok'), child: Text(context.t('submit'))),
          ]),
        ]))),
        if (msg != null) Padding(padding: const EdgeInsets.all(8), child: Text(msg!, style: const TextStyle(color: Colors.indigo))),
        const SizedBox(height: 12),
        Text(context.t('notifications'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
        if (notifications.isEmpty) Padding(padding: const EdgeInsets.all(8), child: Text(context.t('no_notifications'))),
        for (final n in notifications)
          ListTile(
            leading: Icon(n['read_at'] == null ? Icons.notifications_active : Icons.notifications_none),
            title: Text('${n['data']?['event'] ?? n['type']}'),
            subtitle: Text('${n['data']?['starts_at'] ?? n['data']?['instrument'] ?? ''}  ${n['created_at']}'),
          ),
      ]),
    );
  }
}
