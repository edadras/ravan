/// Plain data models mirroring the Laravel API responses. Text fields are kept as
/// {fa, en, tr} maps and resolved with `context.pick(...)` at render time.
library;

class CurrentUser {
  CurrentUser({required this.id, required this.name, required this.role, this.email, this.locale});
  final int id;
  final String name;
  final String role;
  final String? email;
  final String? locale;

  factory CurrentUser.fromJson(Map<String, dynamic> j) =>
      CurrentUser(id: j['id'] as int, name: j['name'] as String, role: j['role'] as String, email: j['email'] as String?, locale: j['locale'] as String?);
}

Map<String, dynamic> _tri(Map<String, dynamic> j, String base) => {'fa': j['${base}_fa'], 'en': j['${base}_en'], 'tr': j['${base}_tr']};

class Clinician {
  Clinician({required this.id, required this.name, this.title, required this.bio, required this.specialties, required this.languages, required this.fee, required this.currency, required this.modes, required this.sessionMinutes, this.rating});
  final int id;
  final String name;
  final String? title;
  final Map<String, dynamic> bio;
  final List<Map<String, dynamic>> specialties; // {fa,en,tr}
  final List<String> languages;
  final int fee;
  final String currency;
  final List<String> modes;
  final int sessionMinutes;
  final double? rating;

  factory Clinician.fromJson(Map<String, dynamic> j) => Clinician(
        id: j['id'] as int,
        name: (j['user']?['name'] ?? '') as String,
        title: j['title'] as String?,
        bio: _tri(j, 'bio'),
        specialties: ((j['specialties'] ?? []) as List).map((s) => _tri(s as Map<String, dynamic>, 'name')).toList(),
        languages: ((j['languages'] ?? []) as List).cast<String>(),
        fee: (j['session_fee'] ?? 0) as int,
        currency: (j['currency'] ?? 'IRR') as String,
        modes: ((j['session_modes'] ?? []) as List).cast<String>(),
        sessionMinutes: (j['session_length_min'] ?? 50) as int,
        rating: (j['rating_avg'] as num?)?.toDouble(),
      );
}

class TranscriptSegment {
  TranscriptSegment({required this.uuid, required this.speaker, required this.tStartMs, required this.tEndMs, required this.text, this.isQuestion = false});
  final String uuid;
  final String speaker;
  final int tStartMs;
  final int tEndMs;
  final String text;
  final bool isQuestion;

  factory TranscriptSegment.fromJson(Map<String, dynamic> j) => TranscriptSegment(
      uuid: j['uuid'] as String, speaker: j['speaker'] as String, tStartMs: j['t_start_ms'] as int, tEndMs: j['t_end_ms'] as int,
      text: j['text'] as String, isQuestion: (j['is_question'] ?? false) as bool);
}

/// A clinician-facing observation. Never contains a diagnosis (diagnosticClaim is always null).
class BehaviorEvent {
  BehaviorEvent({
    required this.uuid, required this.signalId, required this.group, required this.tier, required this.tStartMs, required this.tEndMs,
    required this.observation, this.baselineValue, this.observedValue, this.delta, this.deltaRatio, this.zScore,
    this.unit, required this.confidence, required this.quality, required this.context, required this.possibleContexts,
    required this.clinicalRationale, required this.clinicalNote, required this.memberEventUuids, required this.clinicianStatus, this.transcriptText,
  });
  final String uuid, signalId, group, tier;
  final int tStartMs, tEndMs;
  final Map<String, dynamic> observation; // {fa,en,tr}
  final double? baselineValue, observedValue, delta, deltaRatio, zScore;
  final String? unit;
  final double confidence;
  final Map<String, dynamic> quality;
  final Map<String, dynamic> context;
  final List<Map<String, dynamic>> possibleContexts; // {key,fa,en,tr}
  final Map<String, dynamic> clinicalRationale, clinicalNote;
  final List<String> memberEventUuids;
  String clinicianStatus;
  final String? transcriptText;
  final Null diagnosticClaim = null;

  factory BehaviorEvent.fromJson(Map<String, dynamic> j) => BehaviorEvent(
        uuid: j['uuid'] as String, signalId: j['signal_id'] as String, group: j['group'] as String, tier: j['tier'] as String,
        tStartMs: j['t_start_ms'] as int, tEndMs: j['t_end_ms'] as int,
        observation: j['observation'] is Map ? (j['observation'] as Map<String, dynamic>) : _tri(j, 'observation'),
        baselineValue: (j['baseline_value'] as num?)?.toDouble(), observedValue: (j['observed_value'] as num?)?.toDouble(),
        delta: (j['delta'] as num?)?.toDouble(), deltaRatio: (j['delta_ratio'] as num?)?.toDouble(), zScore: (j['z_score'] as num?)?.toDouble(),
        unit: j['unit'] as String?, confidence: (j['confidence'] as num).toDouble(),
        quality: (j['quality'] ?? {}) as Map<String, dynamic>, context: (j['context'] ?? {}) as Map<String, dynamic>,
        possibleContexts: ((j['possible_contexts'] ?? []) as List).cast<Map<String, dynamic>>(),
        clinicalRationale: j['clinical_rationale'] is Map ? (j['clinical_rationale'] as Map<String, dynamic>) : _tri(j, 'clinical_rationale'),
        clinicalNote: j['clinical_note'] is Map ? (j['clinical_note'] as Map<String, dynamic>) : _tri(j, 'clinical_note'),
        memberEventUuids: ((j['member_event_uuids'] ?? j['member_events'] ?? []) as List).cast<String>(),
        clinicianStatus: (j['clinician_status'] ?? 'unreviewed') as String,
        transcriptText: j['transcript_segment']?['text'] as String?,
      );

  String get timeLabel {
    final s = tStartMs ~/ 1000;
    return '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';
  }
}
