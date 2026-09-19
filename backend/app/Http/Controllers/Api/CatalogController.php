<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BehaviorSignal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only view of the signal catalog for clinician UI (legend, filters, explanations). */
class CatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = BehaviorSignal::query()->where('is_enabled', true)
            ->when($request->filled('group'), fn ($b) => $b->where('group', $request->input('group')))
            ->when($request->filled('tier'), fn ($b) => $b->where('tier', $request->input('tier')))
            ->orderBy('group')->orderBy('signal_id');

        return response()->json(['version' => BehaviorSignal::max('catalog_version'), 'signals' => $q->get()]);
    }

    public function show(string $signalId): JsonResponse
    {
        return response()->json(BehaviorSignal::where('signal_id', $signalId)->firstOrFail());
    }
}
