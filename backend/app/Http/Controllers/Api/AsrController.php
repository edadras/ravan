<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsentType;
use App\Http\Controllers\Controller;
use App\Models\AsrJob;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Speech recognition bridge. The client records short audio chunks of ITS OWN microphone
 * (so the speaker is known without diarization models) and uploads them here; we forward
 * them to the ASR service, which returns timed segments that become transcript_segments.
 * Audio is not stored: it is streamed to the ASR service and discarded.
 */
class AsrController extends Controller
{
    public function __construct(protected TranscriptController $transcripts) {}

    public function chunk(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        abort_unless($session->hasActiveConsent(ConsentType::Transcription), 403, __('messages.transcription_consent_missing'));
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:20480', 'mimetypes:audio/webm,audio/ogg,audio/wav,audio/x-wav,audio/mpeg,audio/mp4,video/webm,application/octet-stream'],
            't_start_ms' => ['required', 'integer', 'min:0'],
            'duration_ms' => ['required', 'integer', 'min:100', 'max:120000'],
            'language' => ['nullable', 'in:fa,en,tr'],
        ]);
        $speaker = $request->user()->id === $session->clinician_id ? 'clinician' : 'patient';
        $job = AsrJob::create(['therapy_session_id' => $session->id, 'speaker' => $speaker, 't_start_ms' => $data['t_start_ms'], 'duration_ms' => $data['duration_ms']]);
        try {
            $res = Http::baseUrl(rtrim((string) config('ravan.asr.base_url'), '/'))->timeout(60)->withToken((string) config('ravan.asr.token'))
                ->attach('audio', file_get_contents($data['audio']->getRealPath()), 'chunk.webm')
                ->post('/transcribe', ['language' => $data['language'] ?? $session->language, 'speaker' => $speaker, 't_offset_ms' => $data['t_start_ms']])
                ->throw()->json();
        } catch (\Throwable $e) {
            $job->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return response()->json(['job' => $job->uuid, 'segments' => []], 202);
        }
        $segments = collect($res['segments'] ?? [])->map(fn ($s) => [
            'speaker' => $speaker, 't_start_ms' => (int) $s['t_start_ms'], 't_end_ms' => (int) $s['t_end_ms'], 'text' => $s['text'],
            'confidence' => (float) ($s['confidence'] ?? 1.0), 'is_question' => (bool) ($s['is_question'] ?? false), 'language' => $res['language'] ?? ($data['language'] ?? $session->language),
        ])->filter(fn ($s) => trim($s['text']) !== '')->values()->all();
        $job->update(['status' => 'done', 'backend' => $res['backend'] ?? null, 'segments' => count($segments)]);
        if (! $segments) {
            return response()->json(['job' => $job->uuid, 'segments' => []]);
        }
        $request->merge(['segments' => $segments]);
        $stored = $this->transcripts->store($request, $session);

        return response()->json(['job' => $job->uuid, 'segments' => $stored->getData(true)['segments'] ?? []]);
    }
}
