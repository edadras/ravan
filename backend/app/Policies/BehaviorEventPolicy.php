<?php

namespace App\Policies;

use App\Models\BehaviorEvent;
use App\Models\User;

class BehaviorEventPolicy
{
    public function review(User $user, BehaviorEvent $event): bool
    {
        return $user->id === $event->session->clinician_id;
    }
}
