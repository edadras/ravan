<?php

namespace App\Policies;

use App\Models\TherapySession;
use App\Models\User;

class TherapySessionPolicy
{
    public function view(User $user, TherapySession $session): bool
    {
        return $session->isParticipant($user) || $user->isAdmin();
    }

    public function join(User $user, TherapySession $session): bool
    {
        return $session->isParticipant($user);
    }

    /** Behaviour events, baselines and reports are clinician-only. */
    public function viewAnalysis(User $user, TherapySession $session): bool
    {
        return $user->id === $session->clinician_id;
    }

    public function manageConsent(User $user, TherapySession $session): bool
    {
        return $user->id === $session->patient_id;
    }

    public function end(User $user, TherapySession $session): bool
    {
        return $session->isParticipant($user);
    }
}
