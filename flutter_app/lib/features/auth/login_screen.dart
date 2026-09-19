import 'package:flutter/material.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';

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
        await auth.register(name.text.trim(), email.text.trim(), password.text);
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
      body: Center(
        child: SizedBox(
          width: 380,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                const Text('روان', textAlign: TextAlign.center, style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
                const SizedBox(height: 16),
                if (register) TextField(controller: name, decoration: const InputDecoration(labelText: 'نام')),
                TextField(controller: email, decoration: const InputDecoration(labelText: 'ایمیل'), keyboardType: TextInputType.emailAddress),
                TextField(controller: password, decoration: const InputDecoration(labelText: 'رمز عبور'), obscureText: true, onSubmitted: (_) => _submit()),
                const SizedBox(height: 16),
                if (error != null) Text(error!, style: const TextStyle(color: Colors.red)),
                FilledButton(onPressed: busy ? null : _submit, child: Text(register ? 'ثبت‌نام' : 'ورود')),
                TextButton(onPressed: () => setState(() => register = !register), child: Text(register ? 'حساب دارم — ورود' : 'حساب ندارم — ثبت‌نام (فقط مراجع)')),
                const Text('حساب درمانگران توسط ادمین پس از احراز هویت ساخته می‌شود.', style: TextStyle(fontSize: 11, color: Colors.black54), textAlign: TextAlign.center),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
