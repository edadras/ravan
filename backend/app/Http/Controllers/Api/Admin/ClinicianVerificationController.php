<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Models\ClinicianProfile;
use App\Models\Specialty;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClinicianVerificationController extends Controller
{
    public function __construct(protected AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(ClinicianProfile::with(['user:id,name,email,phone', 'specialties', 'documents', 'verifications.reviewer:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('verification_status', $request->input('status')))
            ->orderBy('created_at')->paginate(25));
    }

    /** Admin creates the clinician account and profile; the clinician then uploads documents. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'title' => ['nullable', 'string', 'max:64'],
            'license_number' => ['required', 'string', 'max:64'],
            'license_authority' => ['required', 'string', 'max:128'],
            'license_expires_at' => ['nullable', 'date'],
            'specialties' => ['array'], 'specialties.*' => ['exists:specialties,id'],
            'languages' => ['array'],
            'session_fee' => ['integer', 'min:0'],
            'session_length_min' => ['integer', 'between:15,180'],
            'session_modes' => ['array'], 'session_modes.*' => ['in:text,audio,video'],
            'bio_fa' => ['nullable', 'string'], 'bio_en' => ['nullable', 'string'], 'bio_tr' => ['nullable', 'string'],
        ]);
        $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
            'password' => Str::random(32), 'role' => Role::Clinician->value]);
        $profile = ClinicianProfile::create(collect($data)->except(['name', 'email', 'phone', 'specialties'])->toArray() + ['user_id' => $user->id]);
        $profile->specialties()->sync($data['specialties'] ?? []);
        $this->audit->log($request->user(), 'clinician.created', $profile);

        return response()->json($profile->load('user', 'specialties'), 201);
    }

    public function uploadDocument(Request $request, ClinicianProfile $clinician): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:license,degree,id_card,photo'], 'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png']]);
        $file = $data['file'];
        $path = $file->store("clinician-documents/{$clinician->id}", 'local');
        $doc = $clinician->documents()->create([
            'type' => $data['type'], 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(), 'size_bytes' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getRealPath()),
        ]);

        return response()->json($doc, 201);
    }

    public function decide(Request $request, ClinicianProfile $clinician): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'suspended', 'info_requested'])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'checked_items' => ['nullable', 'array'],
        ]);
        $clinician->verifications()->create($data + ['reviewed_by' => $request->user()->id]);
        $status = match ($data['decision']) {
            'approved' => VerificationStatus::Approved,
            'rejected' => VerificationStatus::Rejected,
            'suspended' => VerificationStatus::Suspended,
            default => VerificationStatus::Pending,
        };
        $clinician->update(['verification_status' => $status, 'verified_at' => $status === VerificationStatus::Approved ? now() : null]);
        $this->audit->log($request->user(), 'clinician.verification.'.$data['decision'], $clinician);

        return response()->json($clinician->fresh(['verifications', 'user']));
    }

    public function specialtiesStore(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'alpha_dash', 'unique:specialties,slug'], 'name_fa' => ['required'], 'name_en' => ['required'], 'name_tr' => ['required']]);

        return response()->json(Specialty::create($data), 201);
    }

    public function document(ClinicianProfile $clinician, int $documentId)
    {
        $doc = $clinician->documents()->findOrFail($documentId);

        return Storage::disk('local')->download($doc->path, $doc->original_name);
    }
}
