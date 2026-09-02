<?php

declare(strict_types=1);

namespace App\Enum;

enum PmProvider: string
{
    case Jira = 'jira';
    case Asana = 'asana';
    case Linear = 'linear';
    case Monday = 'monday';

    public function label(): string
    {
        return match ($this) {
            self::Jira => 'Jira',
            self::Asana => 'Asana',
            self::Linear => 'Linear',
            self::Monday => 'Monday.com',
        };
    }

    public function usesOAuth(): bool
    {
        return match ($this) {
            self::Jira, self::Monday => true,
            self::Asana, self::Linear => false,
        };
    }
}
