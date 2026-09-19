<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConsentType;
use App\Events\TranscriptSegmentCreated;
use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use App\Services\AnalysisServiceClient;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TranscriptController extends Controller
{
    public function __construct(protected AnalysisServiceClient $analysis, protected AuditLogger $audit) {}

    public function index(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('view', $session);
        $this->audit->access($request->user(), $session, 'transcript');

        return response()->json($session->transcriptSegments()->paginate(200));
    }

    /**
     * Segments arrive from the ASR worker (server side) or from the clinician client running
     * on-device recognition. Requires transcription consent. Forwarded to the analysis service.
     */
    public function store(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        abort_unless($session->hasActiveConsent(ConsentType::Transcription), 403, 'transcription consent missing');
        $data = $request->validate([
            'segments' => ['required', 'array', 'max:50'],
            'segments.*.speaker' => ['required', 'in:patient,clinician,unknown'],
            'segments.*.t_start_ms' => ['required', 'integer', 'min:0'],
            'segments.*.t_end_ms' => ['required', 'integer', 'gte:segments.*.t_start_ms'],
            'segments.*.text' => ['required', 'string', 'max:5000'],
            'segments.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'segments.*.is_question' => ['nullable', 'boolean'],
            'segments.*.topic' => ['nullable', 'string', 'max:64'],
            'segments.*.language' => ['nullable', 'string', 'max:8'],
        ]);
        $created = [];
        foreach ($data['segments'] as $s) {
            $seg = $session->transcriptSegments()->create($s + ['language' => $s['language'] ?? $session->language]);
            TranscriptSegmentCreated::dispatch($seg);
            $created[] = $seg;
        }
        if ($session->analysis_enabled && $session->analysis_session_ref) {
            try {
                $this->analysis->pushTranscript($session, array_map(fn ($s) => [
                    't_start_ms' => $s['t_start_ms'], 't_end_ms' => $s['t_end_ms'], 'speaker' => $s['speaker'], 'text' => $s['text'],
                    'confidence' => $s['confidence'] ?? 1.0, 'is_question' => (bool) ($s['is_question'] ?? false),
                    'language' => $s['language'] ?? $session->language,
                ], $data['segments']));
            } catch (\Throwable $e) {
                Log::warning('transcript forward failed', ['session' => $session->uuid, 'err' => $e->getMessage()]);
            }
        }

        return response()->json(['segments' => $created], 201);
    }
}
