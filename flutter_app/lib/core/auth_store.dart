import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api_client.dart';
import 'models.dart';

class AuthStore extends ChangeNotifier {
  AuthStore(this.api);

  final ApiClient api;
  CurrentUser? user;

  bool get isLoggedIn => user != null;
  bool get isClinician => user?.role == 'clinician';
  bool get isAdmin => user?.role == 'admin';

  Future<void> restore() async {
    final prefs = await SharedPreferences.getInstance();
    final t = prefs.getString('token');
    if (t == null) return;
    api.token = t;
    try {
      user = CurrentUser.fromJson(await api.get('/auth/me') as Map<String, dynamic>);
    } catch (_) {
      api.token = null;
      await prefs.remove('token');
    }
    notifyListeners();
  }

  Future<void> login(String email, String password) async {
    final res = await api.post('/auth/login', {'email': email, 'password': password, 'device': 'web'}) as Map<String, dynamic>;
    api.token = res['token'] as String;
    (await SharedPreferences.getInstance()).setString('token', api.token!);
    user = CurrentUser.fromJson(res['user'] as Map<String, dynamic>);
    notifyListeners();
  }

  Future<void> register(String name, String email, String password) async {
    final res = await api.post('/auth/register', {'name': name, 'email': email, 'password': password, 'locale': 'fa'}) as Map<String, dynamic>;
    api.token = res['token'] as String;
    (await SharedPreferences.getInstance()).setString('token', api.token!);
    user = CurrentUser.fromJson(res['user'] as Map<String, dynamic>);
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } catch (_) {}
    api.token = null;
    user = null;
    (await SharedPreferences.getInstance()).remove('token');
    notifyListeners();
  }
}

class AuthScope extends InheritedNotifier<AuthStore> {
  const AuthScope({super.key, required AuthStore auth, required super.child}) : super(notifier: auth);

  static AuthStore of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<AuthScope>()!.notifier!;
}
