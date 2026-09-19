<?php

namespace App\Enums;

enum SessionMode: string
{
    case Text = 'text';
    case Audio = 'audio';
    case Video = 'video';
}
