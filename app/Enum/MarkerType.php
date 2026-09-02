<?php

declare(strict_types=1);

namespace App\Enum;

enum MarkerType: string
{
    case Decision = 'decision';
    case Action = 'action';
    case Note = 'note';
    case Risk = 'risk';
    case ParkingLot = 'parking_lot';
    case Important = 'important';
}
