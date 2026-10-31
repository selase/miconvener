<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityInboxRead;
use App\Models\Tenant;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;

final class InboxReads
{
    public function __construct(private InboxQuery $query) {}

    public function mark(Session $session, Tenant $tenant, string $itemId, string $context): void
    {
        (new CommunityInboxRead)->getConnection()->transaction(function () use ($session, $tenant, $itemId, $context): void {
            $this->query->assertOrganisation($session, $tenant);
            $target = $this->query->base($session, $tenant, $context)->where('i.id', $itemId)->selectRaw('COALESCE(i.message_id,i.id) AS entry_key')->first();
            abort_if($target === null, 404);
            $this->insert($this->query->base($session, $tenant, $context)->whereRaw('COALESCE(i.message_id,i.id)=?::uuid', [$target->entry_key]));
        });
    }

    public function markAll(Session $session, Tenant $tenant): void
    {
        (new CommunityInboxRead)->getConnection()->transaction(function () use ($session, $tenant): void {
            $this->query->assertOrganisation($session, $tenant);
            $this->insert($this->query->base($session, $tenant));
        });
    }

    private function insert(Builder $visible): void
    {
        $rows = $visible->whereNull('r.id')->selectRaw('gen_random_uuid(), i.id, COALESCE(i.message_id,i.id), a.reader_key, CURRENT_TIMESTAMP');
        (new CommunityInboxRead)->getConnection()->table('community_inbox_reads')->insertOrIgnoreUsing(['id', 'item_id', 'entry_key', 'reader_key', 'read_at'], $rows);
    }
}
