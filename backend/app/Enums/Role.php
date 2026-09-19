<?php

namespace App\Enums;

enum Role: string
{
    case Patient = 'patient';
    case Clinician = 'clinician';
    case Admin = 'admin';
}
