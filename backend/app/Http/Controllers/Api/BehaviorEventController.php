<?php

namespace App\Http\Controllers\Api;

use App\Enums\EventReviewStatus;
use App\Http\Controllers\Controller;
use App\Models\BehaviorEvent;
use App\Models\TherapySession;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BehaviorEventController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    /** Clinician-only timeline. Patients never see behaviour events. */
    public function index(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('viewAnalysis', $session);
        $this->audit->access($request->user(), $session, 'events');
        $q = $session->behaviorEvents()
            ->when($request->filled('since_ms'), fn ($b) => $b->where('t_end_ms', '>=', (int) $request->input('since_ms')))
            ->when($request->filled('tier'), fn ($b) => $b->whereIn('tier', explode(',', $request->input('tier'))))
            ->when($request->filled('group'), fn ($b) => $b->whereIn('group', explode(',', $request->input('group'))))
            ->when($request->filled('min_confidence'), fn ($b) => $b->where('confidence', '>=', (float) $request->input('min_confidence')))
            ->when($request->filled('status'), fn ($b) => $b->where('clinician_status', $request->input('status')))
            ->with('transcriptSegment:id,uuid,speaker,t_start_ms,t_end_ms,text');

        return response()->json($q->paginate(100));
    }

    public function show(Request $request, BehaviorEvent $event): JsonResponse
    {
        $this->authorize('viewAnalysis', $event->session);
        $members = $event->member_event_uuids ? BehaviorEvent::whereIn('uuid', $event->member_event_uuids)->get() : collect();
        // Transcript around the event so the clinician can read what was said.
        $window = $event->session->transcriptSegments()
            ->where('t_end_ms', '>=', max(0, $event->t_start_ms - 20000))
            ->where('t_start_ms', '<=', $event->t_end_ms + 10000)->get();

        return response()->json(['event' => $event->load('reviews'), 'members' => $members, 'transcript' => $window]);
    }

    /** Mark relevant / Dismiss / Add note. */
    public function review(Request $request, BehaviorEvent $event): JsonResponse
    {
        $this->authorize('review', $event);
        $data = $request->validate([
            'status' => ['required', Rule::in([EventReviewStatus::Relevant->value, EventReviewStatus::Dismissed->value, EventReviewStatus::Noted->value])],
            'note' => ['nullable', 'string', 'max:2000'],
            'selected_context' => ['nullable', 'string', 'max:48'],
        ]);
        $event->reviews()->create($data + ['clinician_id' => $request->user()->id]);
        $event->update(['clinician_status' => $data['status']]);
        $this->audit->log($request->user(), 'event.reviewed', $event, ['status' => $data['status']]);

        return response()->json($event->fresh('reviews'));
    }
}
