<?php

namespace App\Enums;

enum ConsentType: string
{
    case VideoCall = 'video_call';
    case BehaviorAnalysis = 'behavior_analysis';
    case Transcription = 'transcription';
}
