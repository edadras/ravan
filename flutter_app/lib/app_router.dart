import 'package:go_router/go_router.dart';

import 'core/auth_store.dart';
import 'features/admin/admin_verification_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/register_screen.dart';
import 'features/auth/reset_password_screen.dart';
import 'features/clinicians/clinician_detail_screen.dart';
import 'features/clinicians/clinician_list_screen.dart';
import 'features/home/home_screen.dart';
import 'features/messaging/conversations_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/record/clinician_record_screen.dart';
import 'features/record/patient_record_screen.dart';
import 'features/report/report_screen.dart';
import 'features/session/doctor_console_screen.dart';
import 'features/session/patient_session_screen.dart';

GoRouter buildRouter(AuthStore auth) {
  return GoRouter(
    initialLocation: '/',
    refreshListenable: auth,
    redirect: (context, state) {
      final loggedIn = auth.isLoggedIn;
      final loc = state.matchedLocation;
      final public = loc == '/login' || loc == '/register' || loc.startsWith('/reset') || loc.startsWith('/clinicians');
      if (!loggedIn && !public) return '/login';
      if (loggedIn && (loc == '/login' || loc == '/register')) return '/';
      return null;
    },
    routes: [
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),
      GoRoute(path: '/reset', builder: (_, __) => const ResetPasswordScreen()),
      GoRoute(path: '/reset/code-login', builder: (_, __) => const ResetPasswordScreen(codeLogin: true)),
      GoRoute(path: '/', builder: (_, __) => const HomeScreen()),
      GoRoute(path: '/profile', builder: (_, __) => const ProfileScreen()),
      GoRoute(path: '/clinicians', builder: (_, __) => const ClinicianListScreen()),
      GoRoute(path: '/clinicians/:id', builder: (_, s) => ClinicianDetailScreen(id: int.parse(s.pathParameters['id']!))),
      GoRoute(path: '/session/:uuid', builder: (_, s) => PatientSessionScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/console/:uuid', builder: (_, s) => DoctorConsoleScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/report/:uuid', builder: (_, s) => ReportScreen(uuid: s.pathParameters['uuid']!)),
      GoRoute(path: '/record', builder: (_, __) => const PatientRecordScreen()),
      GoRoute(path: '/records/:patientId', builder: (_, s) => ClinicianRecordScreen(patientId: int.parse(s.pathParameters['patientId']!), sessionUuid: s.uri.queryParameters['session'])),
      GoRoute(path: '/messages', builder: (_, s) => ConversationsScreen(openWithUserId: s.uri.queryParameters['with'] != null ? int.tryParse(s.uri.queryParameters['with']!) : null)),
      GoRoute(path: '/admin/clinicians', builder: (_, __) => const AdminVerificationScreen()),
    ],
  );
}
