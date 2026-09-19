<?php

namespace App\Policies;

use App\Models\PatientRecord;
use App\Models\User;

class PatientRecordPolicy
{
    /** Patients see a read-only summary of their own record; clinicians with a relationship see all. */
    public function view(User $user, PatientRecord $record): bool
    {
        return $user->id === $record->patient_id || ($user->isClinician() && $record->allowsClinician($user)) || $user->isAdmin();
    }

    public function update(User $user, PatientRecord $record): bool
    {
        return $user->isClinician() && $record->allowsClinician($user);
    }

    /** Intake fields the patient fills in themselves. */
    public function updateIntake(User $user, PatientRecord $record): bool
    {
        return $user->id === $record->patient_id || $this->update($user, $record);
    }

    public function useAi(User $user, PatientRecord $record): bool
    {
        return $this->update($user, $record);
    }
}
