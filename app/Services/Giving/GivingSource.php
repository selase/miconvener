<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\EventContribution;
use App\Models\GivingFund;
use App\Models\GivingGift;

final readonly class GivingSource
{
    public function __construct(public string $type, public string $id, public GivingFund $fund, public int $principal, public string $currency, public GivingGift|EventContribution $model) {}

    public function key(): string
    {
        return $this->type.':'.$this->id;
    }

    /** @return array<string, string|null> */
    public function columns(): array
    {
        return ['gift_id' => $this->type === 'gift' ? $this->id : null, 'event_contribution_id' => $this->type === 'event_contribution' ? $this->id : null];
    }
}
