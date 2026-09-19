<?php

namespace App\Enums;

enum EventReviewStatus: string
{
    case Unreviewed = 'unreviewed';
    case Relevant = 'relevant';
    case Dismissed = 'dismissed';
    case Noted = 'noted';
}
