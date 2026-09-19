import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final email = TextEditingController();
  final password = TextEditingController();
  final name = TextEditingController();
  bool register = false;
  String? error;
  bool busy = false;

  Future<void> _submit() async {
    setState(() { busy = true; error = null; });
    try {
      final auth = AuthScope.of(context);
      if (register) {
        await auth.register(name.text.trim(), email.text.trim(), password.text, context.lang);
      } else {
        await auth.login(email.text.trim(), password.text);
      }
    } on ApiException catch (e) {
      setState(() => error = e.errors?.values.first?.first?.toString() ?? e.message);
    } finally {
      if (mounted) setState(() => busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(backgroundColor: Colors.transparent, elevation: 0, actions: const [LanguageSwitcher()]),
      body: Center(
        child: SizedBox(
          width: 380,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(context.t('app_name'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
                const SizedBox(height: 16),
                if (register) TextField(controller: name, decoration: InputDecoration(labelText: context.t('name'))),
                TextField(controller: email, decoration: InputDecoration(labelText: context.t('email')), keyboardType: TextInputType.emailAddress),
                TextField(controller: password, decoration: InputDecoration(labelText: context.t('password')), obscureText: true, onSubmitted: (_) => _submit()),
                const SizedBox(height: 16),
                if (error != null) Text(error!, style: const TextStyle(color: Colors.red)),
                FilledButton(onPressed: busy ? null : _submit, child: Text(context.t(register ? 'register' : 'login'))),
                TextButton(onPressed: () => setState(() => register = !register), child: Text(context.t(register ? 'have_account' : 'no_account'))),
                Text(context.t('clinician_accounts_note'), style: const TextStyle(fontSize: 11, color: Colors.black54), textAlign: TextAlign.center),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
