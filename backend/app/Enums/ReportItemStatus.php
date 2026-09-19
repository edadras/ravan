<?php

namespace App\Enums;

enum ReportItemStatus: string
{
    case Draft = 'draft';
    case Accepted = 'accepted';
    case Edited = 'edited';
    case Rejected = 'rejected';
}
