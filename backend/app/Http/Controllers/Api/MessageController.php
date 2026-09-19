<?php

namespace App\Http\Controllers\Api;

use App\Events\SessionMessageSent;
use App\Http\Controllers\Controller;
use App\Models\TherapySession;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('view', $session);
        $this->audit->access($request->user(), $session, 'messages');

        return response()->json($session->messages()->with('sender:id,name')->orderBy('id')->paginate(100));
    }

    public function store(Request $request, TherapySession $session): JsonResponse
    {
        $this->authorize('join', $session);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $message = $session->messages()->create([
            'sender_id' => $request->user()->id,
            'body' => $data['body'],
            't_ms' => $session->elapsedMs(),
        ]);
        SessionMessageSent::dispatch($message, $session->uuid);

        return response()->json($message, 201);
    }
}
