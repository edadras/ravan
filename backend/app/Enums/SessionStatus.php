<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Scheduled = 'scheduled';
    case Waiting = 'waiting';
    case Live = 'live';
    case Ended = 'ended';
    case Cancelled = 'cancelled';
}
