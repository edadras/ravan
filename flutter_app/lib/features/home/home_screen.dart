import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth_store.dart';
import '../../core/l10n.dart';

/// Role-aware landing: appointments list + entry to the session room / clinician console / admin.
class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  List<Map<String, dynamic>> appointments = [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final res = await AuthScope.of(context).api.get('/appointments') as Map<String, dynamic>;
    setState(() => appointments = (res['data'] as List).cast<Map<String, dynamic>>());
  }

  @override
  Widget build(BuildContext context) {
    final auth = AuthScope.of(context);
    return Scaffold(
      appBar: AppBar(title: Text('${context.t('app_name')} — ${auth.user?.name ?? ''}'), actions: [
        if (auth.isAdmin) TextButton(onPressed: () => context.go('/admin/clinicians'), child: Text(context.t('verify_clinicians'))),
        if (!auth.isClinician && !auth.isAdmin) TextButton(onPressed: () => context.go('/clinicians'), child: Text(context.t('book'))),
        const LanguageSwitcher(),
        IconButton(onPressed: auth.logout, icon: const Icon(Icons.logout)),
      ]),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView.builder(
          padding: const EdgeInsets.all(12),
          itemCount: appointments.length,
          itemBuilder: (_, i) {
            final a = appointments[i];
            final other = auth.isClinician ? a['patient']?['name'] : a['clinician']?['name'];
            final session = a['session'] as Map<String, dynamic>?;
            final canJoin = session != null && (a['status'] == 'confirmed' || a['status'] == 'pending') && session['status'] != 'ended' && session['status'] != 'cancelled';
            return Card(
              child: ListTile(
                title: Text('$other — ${DateFormat.yMd(context.lang).add_Hm().format(DateTime.parse(a['starts_at'] as String).toLocal())}'),
                subtitle: Text('${context.t('mode_${a['mode']}')} · ${context.t('status')}: ${context.t('status_${a['status']}')}'),
                trailing: Wrap(spacing: 8, children: [
                  if (auth.isClinician && a['status'] == 'pending')
                    OutlinedButton(onPressed: () async { await auth.api.post('/appointments/${a['id']}/confirm'); _load(); }, child: Text(context.t('confirm'))),
                  if (canJoin)
                    FilledButton(onPressed: () => context.go(auth.isClinician ? '/console/${session['uuid']}' : '/session/${session['uuid']}'), child: Text(context.t('join_session'))),
                  if (auth.isClinician && session?['status'] == 'ended')
                    TextButton(onPressed: () => context.go('/report/${session!['uuid']}'), child: Text(context.t('report'))),
                ]),
              ),
            );
          },
        ),
      ),
    );
  }
}
