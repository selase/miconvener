<?php

declare(strict_types=1);

namespace App\Enum;

enum TenantStatusEnum: string
{
    case ACTIVE = 'active';
    case DEACTIVATED = 'deactivated';
    case BANNED = 'banned';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => '<span class="badge badge-light-success">Active</span>',
            self::DEACTIVATED => '<span class="badge badge-light-warning">Deactivated</span>',
            self::BANNED => '<span class="badge badge-light-danger">Banned</span>',
        };
    }
}
