<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityInboxItem;
use Carbon\CarbonImmutable;

final class InboxRetention
{
    public function prune(CarbonImmutable $asOf): int
    {
        $count = 0;
        CommunityInboxItem::query()->where('available_at', '<', $asOf->subMonthsNoOverflow(24))->select('id')->chunkById(500, function ($items) use (&$count): void {
            $count += CommunityInboxItem::query()->whereIn('id', $items->pluck('id'))->delete();
        });

        return $count;
    }
}
