<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReportItemStatus;
use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function show(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $report = $session->report()->with('versions')->firstOrFail();
        $this->audit->access($request->user(), $session, 'report');

        return response()->json($report);
    }

    /**
     * The clinician reviews every AI-drafted item: Accept / Edit / Reject. A new immutable version is
     * stored each time; only the clinician's text ever becomes part of the record.
     */
    public function review(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $report = $session->report()->firstOrFail();
        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.ai_text' => ['nullable', 'string'],
            'items.*.clinician_text' => ['nullable', 'string', 'max:5000'],
            'items.*.status' => ['required', Rule::enum(ReportItemStatus::class)],
            'summary' => ['nullable', 'string', 'max:20000'],
            'finalize' => ['nullable', 'boolean'],
        ]);
        $version = ($report->versions()->max('version') ?? 0) + 1;
        $report->versions()->create(['author_id' => $request->user()->id, 'version' => $version, 'items' => $data['items'], 'summary' => $data['summary'] ?? null]);
        $report->update(['status' => ($data['finalize'] ?? false) ? 'finalized' : 'reviewed'] + (($data['finalize'] ?? false) ? ['finalized_at' => now(), 'finalized_by' => $request->user()->id] : []));
        $this->audit->log($request->user(), 'report.reviewed', $report, ['version' => $version, 'finalized' => (bool) ($data['finalize'] ?? false)]);

        return response()->json($report->fresh('versions'));
    }
}
