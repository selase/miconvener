<?php

declare(strict_types=1);

namespace App\Enum;

enum RecordingMode: string
{
    case PushToTalk = 'push_to_talk';
    case FloorControl = 'floor_control';
    case OpenMic = 'open_mic';
    case AutoVad = 'auto_vad';
}
