import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/api_client.dart';
import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Forgot-password (code by e-mail/SMS → new password) and one-time-code login.
class ResetPasswordScreen extends StatefulWidget {
  const ResetPasswordScreen({super.key, this.codeLogin = false});
  final bool codeLogin;

  @override
  State<ResetPasswordScreen> createState() => _ResetPasswordScreenState();
}

class _ResetPasswordScreenState extends State<ResetPasswordScreen> {
  final target = TextEditingController();
  final code = TextEditingController();
  final password = TextEditingController();
  bool sent = false;
  String? msg;

  Future<void> _send() async {
    final api = AuthScope.of(context).api;
    try {
      await api.post('/auth/code/request', {'target': target.text.trim(), 'purpose': widget.codeLogin ? 'login' : 'reset'});
      setState(() { sent = true; msg = context.t('code_sent'); });
    } on ApiException catch (e) {
      setState(() => msg = e.message);
    }
  }

  Future<void> _finish() async {
    final auth = AuthScope.of(context);
    try {
      if (widget.codeLogin) {
        final res = await auth.api.post('/auth/code/login', {'target': target.text.trim(), 'code': code.text.trim()}) as Map<String, dynamic>;
        await auth.adopt(res);
        if (mounted) context.go('/');
      } else {
        await auth.api.post('/auth/password/reset', {'email': target.text.trim(), 'code': code.text.trim(), 'password': password.text});
        setState(() => msg = context.t('reset_done'));
      }
    } on ApiException catch (e) {
      setState(() => msg = e.errors?.values.first?.first?.toString() ?? e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(context.t(widget.codeLogin ? 'login_with_code' : 'reset_title')), actions: const [LanguageSwitcher()]),
      body: Center(
        child: SizedBox(
          width: 380,
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                TextField(controller: target, decoration: InputDecoration(labelText: widget.codeLogin ? '${context.t('email')} / ${context.t('phone')}' : context.t('email'))),
                if (!sent) FilledButton(onPressed: _send, child: Text(context.t('send_code'))),
                if (sent) ...[
                  TextField(controller: code, decoration: InputDecoration(labelText: context.t('code')), keyboardType: TextInputType.number),
                  if (!widget.codeLogin) TextField(controller: password, decoration: InputDecoration(labelText: context.t('new_password')), obscureText: true),
                  FilledButton(onPressed: _finish, child: Text(context.t(widget.codeLogin ? 'login' : 'submit'))),
                ],
                if (msg != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(msg!)),
                TextButton(onPressed: () => context.go('/login'), child: Text(context.t('have_account'))),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
