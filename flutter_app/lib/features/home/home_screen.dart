import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/auth_store.dart';

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
      appBar: AppBar(title: Text('روان — ${auth.user?.name ?? ''}'), actions: [
        if (auth.isAdmin) TextButton(onPressed: () => context.go('/admin/clinicians'), child: const Text('تأیید درمانگران')),
        if (!auth.isClinician && !auth.isAdmin) TextButton(onPressed: () => context.go('/clinicians'), child: const Text('رزرو نوبت')),
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
                title: Text('$other — ${DateFormat('yyyy/MM/dd HH:mm').format(DateTime.parse(a['starts_at'] as String).toLocal())}'),
                subtitle: Text('${{'text': 'متنی', 'audio': 'صوتی', 'video': 'تصویری'}[a['mode']]} · وضعیت: ${a['status']}'),
                trailing: Wrap(spacing: 8, children: [
                  if (auth.isClinician && a['status'] == 'pending')
                    OutlinedButton(onPressed: () async { await auth.api.post('/appointments/${a['id']}/confirm'); _load(); }, child: const Text('تأیید')),
                  if (canJoin)
                    FilledButton(onPressed: () => context.go(auth.isClinician ? '/console/${session['uuid']}' : '/session/${session['uuid']}'), child: const Text('ورود به جلسه')),
                  if (auth.isClinician && session?['status'] == 'ended')
                    TextButton(onPressed: () => context.go('/report/${session!['uuid']}'), child: const Text('گزارش')),
                ]),
              ),
            );
          },
        ),
      ),
    );
  }
}
