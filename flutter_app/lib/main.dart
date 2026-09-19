import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'app_router.dart';
import 'core/api_client.dart';
import 'core/auth_store.dart';
import 'core/l10n.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final locale = LocaleStore();
  await locale.restore();
  final api = ApiClient(baseUrl: const String.fromEnvironment('RAVAN_API_URL', defaultValue: 'http://localhost:8000/api'), language: () => locale.code);
  final auth = AuthStore(api);
  await auth.restore();
  runApp(RavanApp(auth: auth, locale: locale));
}

class RavanApp extends StatelessWidget {
  const RavanApp({super.key, required this.auth, required this.locale});

  final AuthStore auth;
  final LocaleStore locale;

  @override
  Widget build(BuildContext context) {
    return LocaleScope(
      store: locale,
      child: AuthScope(
        auth: auth,
        child: ListenableBuilder(
          listenable: locale,
          builder: (context, _) => MaterialApp.router(
            title: 'Ravan',
            debugShowCheckedModeBanner: false,
            locale: locale.locale,
            supportedLocales: const [Locale('fa'), Locale('en'), Locale('tr')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: ThemeData(
              useMaterial3: true,
              colorSchemeSeed: const Color(0xFF3B5BDB),
              fontFamily: locale.code == 'fa' ? 'Vazirmatn' : null,
              scaffoldBackgroundColor: const Color(0xFFF6F7FB),
            ),
            routerConfig: buildRouter(auth),
            builder: (context, child) => Directionality(textDirection: locale.direction, child: child!),
          ),
        ),
      ),
    );
  }
}
