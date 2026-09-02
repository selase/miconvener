<?php

declare(strict_types=1);

namespace App\Enum;

enum PmSyncStatus: string
{
    case Synced = 'synced';
    case Pending = 'pending';
    case Error = 'error';
    case NotLinked = 'not_linked';
}
