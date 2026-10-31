<?php

declare(strict_types=1);

namespace App\Enum;

enum MemberStatusEnum: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Lapsed = 'lapsed';
    case Exited = 'exited';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
