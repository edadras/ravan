<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationMessageSent;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Direct patient ↔ clinician messaging outside sessions, with delivery and read receipts, pushed over WebSocket. */
class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $u = $request->user();

        return response()->json(Conversation::with(['patient:id,name', 'clinician:id,name'])
            ->where($u->isClinician() ? 'clinician_id' : 'patient_id', $u->id)
            ->withCount(['messages as unread_count' => fn ($q) => $q->whereNull('read_at')->where('sender_id', '!=', $u->id)])
            ->orderByDesc('last_message_at')->get());
    }

    /** Open (or create) the conversation with a counterpart; allowed only where an appointment exists. */
    public function open(Request $request, User $user): JsonResponse
    {
        $me = $request->user();
        [$patientId, $clinicianId] = $me->isClinician() ? [$user->id, $me->id] : [$me->id, $user->id];
        abort_unless(Appointment::where('patient_id', $patientId)->where('clinician_id', $clinicianId)->exists(), 403, __('messages.no_relationship'));
        $conv = Conversation::firstOrCreate(['patient_id' => $patientId, 'clinician_id' => $clinicianId]);

        return response()->json($conv->load(['patient:id,name', 'clinician:id,name']));
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isParticipant($request->user()), 403);
        $conversation->messages()->whereNull('delivered_at')->where('sender_id', '!=', $request->user()->id)->update(['delivered_at' => now()]);

        return response()->json($conversation->messages()->with('sender:id,name')->paginate(100));
    }

    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isParticipant($request->user()), 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $msg = $conversation->messages()->create(['sender_id' => $request->user()->id, 'body' => $data['body']]);
        $conversation->update(['last_message_at' => now()]);
        ConversationMessageSent::dispatch($msg);

        return response()->json($msg, 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isParticipant($request->user()), 403);
        $n = $conversation->messages()->whereNull('read_at')->where('sender_id', '!=', $request->user()->id)->update(['read_at' => now(), 'delivered_at' => now()]);

        return response()->json(['read' => $n]);
    }
}
