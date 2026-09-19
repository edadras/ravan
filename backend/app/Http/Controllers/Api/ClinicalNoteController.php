<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClinicalNoteController extends Controller
{
    public function index(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);

        return response()->json($session->clinicalNotes()->orderBy('t_ms')->get());
    }

    public function store(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 't_ms' => ['nullable', 'integer', 'min:0'], 'is_private' => ['nullable', 'boolean']]);

        return response()->json($session->clinicalNotes()->create($data + ['clinician_id' => $request->user()->id, 't_ms' => $data['t_ms'] ?? $session->elapsedMs()]), 201);
    }
}
