<?php

declare(strict_types=1);

namespace App\Enum;

enum ChairType: string
{
    case Chair = 'chair';
    case CoChair = 'co_chair';
    case ActingChair = 'acting_chair';

    public function label(): string
    {
        return match ($this) {
            self::Chair => 'Chair',
            self::CoChair => 'Co-Chair',
            self::ActingChair => 'Acting Chair',
        };
    }
}
