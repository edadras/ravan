import 'package:go_router/go_router.dart';

import 'core/auth_store.dart';
import 'features/admin/admin_verification_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/clinicians/clinician_detail_screen.dart';
import 'features/clinicians/clinician_list_screen.dart';
import 'features/home/home_screen.dart';
import 'features/report/report_screen.dart';
import 'features/session/doctor_console_screen.dart';
import 'features/session/patient_session_screen.dart';

GoRouter buildRouter(AuthStore auth) {
  return GoRouter(
    initialLocation: '/',
    refreshListenable: auth,
    redirect: (context, state) {
      final loggedIn = auth.isLoggedIn;
      final onLogin = state.matchedLocation == '/login';
      final public = state.matchedLocation.startsWith('/clinicians');
      if (!loggedIn && !onLogin && !public) return '/login';
      if (loggedIn && onLogin) return '/';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/', builder: (_, __) => const HomeScreen()),
      GoRoute(path: '/clinicians', builder: (_, __) => const ClinicianListScreen()),
      GoRoute(path: '/clinicians/:id', builder: (_, s) => ClinicianDetailScreen(id: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/session/:uuid', builder: (_, s) => PatientSessionScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/console/:uuid', builder: (_, s) => DoctorConsoleScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/report/:uuid', builder: (_, s) => ReportScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/admin/clinicians', builder: (_, __) => const AdminVerificationScreen()),
    ],
  );
}
