import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'app_router.dart';
import 'core/api_client.dart';
import 'core/auth_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final auth = AuthStore(ApiClient(baseUrl: const String.fromEnvironment('RAVAN_API_URL', defaultValue: 'http://localhost:8000/api')));
  await auth.restore();
  runApp(RavanApp(auth: auth));
}

class RavanApp extends StatelessWidget {
  const RavanApp({super.key, required this.auth});

  final AuthStore auth;

  @override
  Widget build(BuildContext context) {
    return AuthScope(
      auth: auth,
      child: MaterialApp.router(
        title: 'روان',
        debugShowCheckedModeBanner: false,
        locale: const Locale('fa'),
        supportedLocales: const [Locale('fa'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: ThemeData(
          useMaterial3: true,
          colorSchemeSeed: const Color(0xFF3B5BDB),
          fontFamily: 'Vazirmatn',
          scaffoldBackgroundColor: const Color(0xFFF6F7FB),
        ),
        routerConfig: buildRouter(auth),
        builder: (context, child) => Directionality(textDirection: TextDirection.rtl, child: child!),
      ),
    );
  }
}
