<?php

declare(strict_types=1);

namespace App\Enum;

enum CommercialStreamEnum: string
{
    case Tickets = 'tickets';
    case Contributions = 'contributions';
    case Giving = 'giving';
    case Dues = 'dues';
}
