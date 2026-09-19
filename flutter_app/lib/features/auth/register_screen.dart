import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Registration for patients (active immediately) and clinicians (pending admin verification).
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final c = {for (final k in ['name', 'email', 'phone', 'password', 'title', 'license_number', 'license_authority']) k: TextEditingController()};
  String role = 'patient';
  bool terms = false;
  bool busy = false;
  String? error;

  Future<void> _submit() async {
    setState(() { busy = true; error = null; });
    try {
      final auth = AuthScope.of(context);
      final res = await auth.api.post('/auth/register', {
        for (final e in c.entries) if (e.value.text.trim().isNotEmpty) e.key: e.value.text.trim(),
        'role': role, 'locale': context.lang, 'accept_terms': terms,
      }) as Map<String, dynamic>;
      await auth.adopt(res);
      if (!mounted) return;
      if (res['clinician_pending_verification'] == true) {
        await showDialog(context: context, builder: (_) => AlertDialog(content: Text(context.t('clinician_pending_msg')), actions: [FilledButton(onPressed: () => Navigator.pop(context), child: Text(context.t('ok')))]));
      }
      if (mounted) context.go('/');
    } on ApiException catch (e) {
      setState(() => error = e.errors?.values.first?.first?.toString() ?? e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t('register')), actions: const [LanguageSwitcher()]),
      body: Center(
        child: SizedBox(
          width: 440,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: ListView(shrinkWrap: true, children: [
                Text(context.t('register_as'), style: const TextStyle(fontWeight: FontWeight.w700)),
                Row(children: [
                  ChoiceChip(label: Text(context.t('role_patient')), selected: role == 'patient', onSelected: (_) => setState(() => role = 'patient')),
                  const SizedBox(width: 8),
                  ChoiceChip(label: Text(context.t('role_clinician')), selected: role == 'clinician', onSelected: (_) => setState(() => role = 'clinician')),
                ]),
                TextField(controller: c['name'], decoration: InputDecoration(labelText: context.t('name'))),
                TextField(controller: c['email'], decoration: InputDecoration(labelText: context.t('email')), keyboardType: TextInputType.emailAddress),
                TextField(controller: c['phone'], decoration: InputDecoration(labelText: context.t('phone')), keyboardType: TextInputType.phone),
                TextField(controller: c['password'], decoration: InputDecoration(labelText: context.t('password')), obscureText: true),
                if (role == 'clinician') ...[
                  TextField(controller: c['title'], decoration: InputDecoration(labelText: context.t('title_field'))),
                  TextField(controller: c['license_number'], decoration: InputDecoration(labelText: context.t('license_no_field'))),
                  TextField(controller: c['license_authority'], decoration: InputDecoration(labelText: context.t('license_authority'))),
                ],
                CheckboxListTile(value: terms, onChanged: (v) => setState(() => terms = v ?? false), title: Text(context.t('accept_terms'), style: const TextStyle(fontSize: 13)), controlAffinity: ListTileControlAffinity.leading),
                if (error != null) Text(error!, style: const TextStyle(color: Colors.red)),
                FilledButton(onPressed: busy || !terms ? null : _submit, child: Text(context.t('register'))),
                TextButton(onPressed: () => context.go('/login'), child: Text(context.t('have_account'))),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
