<?php

declare(strict_types=1);

namespace App\Enum;

enum DuesChargeStatusEnum: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Waived = 'waived';
    case Void = 'void';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
