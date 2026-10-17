<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityInboxItem;
use App\Models\CommunityMessage;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

final class InboxWriter
{
    public function __construct(private InboxRollout $rollout) {}

    public function announcement(CommunityMessage $message, InboxRecipient $recipient, CarbonImmutable $availableAt): void
    {
        $tenant = Tenant::query()->find($message->tenant_id);
        if ($tenant === null || $recipient->tenantId !== $tenant->id || ! $this->rollout->enabled($tenant) || $message->inbox_withdrawn_at !== null || ! in_array($message->status, ['queued', 'scheduled', 'sending', 'completed', 'partial'], true)) {
            return;
        }
        $column = $recipient->type === 'member' ? 'member_id' : 'follower_id';
        CommunityInboxItem::query()->firstOrCreate(['message_id' => $message->id, $column => $recipient->id], ['tenant_id' => $tenant->id, 'kind' => 'announcement', 'recipient_type' => $recipient->type, 'available_at' => $availableAt]);
    }

    public function withdrawMessage(CommunityMessage $message): void
    {
        $message->update(['inbox_withdrawn_at' => $message->inbox_withdrawn_at ?? now()]);
        CommunityInboxItem::query()->where('tenant_id', $message->tenant_id)->where('message_id', $message->id)->whereNull('withdrawn_at')->update(['withdrawn_at' => now()]);
    }

    /** @return array{inbox_count:int,inbox_read_count:?int} */
    public function statistics(CommunityMessage $message): array
    {
        $query = CommunityInboxItem::query()->where('tenant_id', $message->tenant_id)->where('message_id', $message->id)->whereNull('withdrawn_at');
        $count = (clone $query)->count();

        return ['inbox_count' => $count, 'inbox_read_count' => $count < 10 ? null : $query->whereHas('reads')->count()];
    }
}
