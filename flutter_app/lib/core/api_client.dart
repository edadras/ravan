import 'dart:convert';

import 'package:http/http.dart' as http;

class ApiException implements Exception {
  ApiException(this.status, this.message, [this.errors]);
  final int status;
  final String message;
  final Map<String, dynamic>? errors;
  @override
  String toString() => 'ApiException($status): $message';
}

/// Minimal JSON client for the Laravel API. Token is a Sanctum personal access token.
/// Every request carries `Accept-Language` so server-side messages come back in the UI language.
class ApiClient {
  ApiClient({required this.baseUrl, required this.language});

  final String baseUrl;
  final String Function() language;
  String? token;

  Map<String, String> get _headers => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Accept-Language': language(),
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<dynamic> get(String path, {Map<String, String>? query}) async {
    final uri = Uri.parse('$baseUrl$path').replace(queryParameters: query);
    return _handle(await http.get(uri, headers: _headers));
  }

  Future<dynamic> post(String path, [Object? body]) async {
    return _handle(await http.post(Uri.parse('$baseUrl$path'), headers: _headers, body: jsonEncode(body ?? {})));
  }

  Future<dynamic> patch(String path, [Object? body]) async {
    return _handle(await http.patch(Uri.parse('$baseUrl$path'), headers: _headers, body: jsonEncode(body ?? {})));
  }

  dynamic _handle(http.Response r) {
    final data = r.body.isEmpty ? null : jsonDecode(utf8.decode(r.bodyBytes));
    if (r.statusCode >= 200 && r.statusCode < 300) return data;
    final msg = data is Map ? (data['message'] ?? r.reasonPhrase ?? 'error') : (r.reasonPhrase ?? 'error');
    throw ApiException(r.statusCode, msg.toString(), data is Map ? (data['errors'] as Map<String, dynamic>?) : null);
  }
}
