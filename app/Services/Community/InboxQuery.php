<?php

declare(strict_types=1);

namespace App\Services\Community;

use App\Models\CommunityInboxItem;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use stdClass;

final class InboxQuery
{
    public function __construct(private InboxAccess $access, private InboxRollout $rollout) {}

    public function available(Session $session): bool
    {
        return $this->rollout->organisations()->whereIn('id', array_values(array_unique(array_column($this->access->contexts($session), 'tenant_id'))))->exists();
    }

    /** @return list<array<string,mixed>> */
    public function counts(Session $session): array
    {
        $rows = (new CommunityInboxItem)->getConnection()->query()->fromSub($this->grouped($session), 'entries')->selectRaw('tenant_id, SUM(CASE WHEN is_read=0 THEN 1 ELSE 0 END) as unread')->groupBy('tenant_id')->get();
        $totals = $rows->keyBy('tenant_id');
        $ids = array_values(array_unique(array_column($this->access->contexts($session), 'tenant_id')));
        $tenants = $this->rollout->organisations()->whereIn('id', $ids)->orderBy('name')->get();

        return $tenants->map(fn (Tenant $tenant): array => ['slug' => $tenant->slug, 'name' => $tenant->name, 'logo_url' => $tenant->logo_url, 'unread' => (int) ($totals[$tenant->id]->unread ?? 0)])->values()->all();
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function entries(Session $session, Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $this->assertOrganisation($session, $tenant);

        return $this->grouped($session, $tenant, $filters['context'] ?? null)->orderByDesc('available_at')->orderByDesc('entry_key')->orderBy('context_key')->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1))->through(fn (stdClass $row): array => $this->payload($row));
    }

    /** @return array<string,mixed> */
    public function entry(Session $session, Tenant $tenant, string $itemId, string $context = 'self'): array
    {
        $this->assertOrganisation($session, $tenant);
        $target = $this->base($session, $tenant, $context)->where('i.id', $itemId)->selectRaw('COALESCE(i.message_id,i.id) AS entry_key')->first();
        abort_if($target === null, 404, 'This message is no longer available.');
        $row = $this->grouped($session, $tenant, $context, true)->whereRaw('COALESCE(i.message_id,i.id) = ?::uuid', [$target->entry_key])->first();
        abort_if($row === null, 404, 'This message is no longer available.');

        return $this->payload($row, true);
    }

    public function assertOrganisation(Session $session, Tenant $tenant): void
    {
        abort_unless($this->rollout->enabled($tenant), 404);
        foreach ($this->access->contexts($session) as $context) {
            if ($context['tenant_id'] === $tenant->id) {
                return;
            }
        }
        abort(404);
    }

    public function base(Session $session, ?Tenant $tenant = null, ?string $context = null): Builder
    {
        $connection = (new CommunityInboxItem)->getConnection();
        $contexts = $this->access->contexts($session);
        if ($tenant !== null) {
            $contexts = array_values(array_filter($contexts, fn (array $row): bool => $row['tenant_id'] === $tenant->id));
        }
        $access = $connection->query()->fromRaw('jsonb_to_recordset(?::jsonb) AS allowed(tenant_id uuid,recipient_id uuid,recipient_type text,reader_key text,context_key text,label text,consented_at timestamptz)', [json_encode($contexts, JSON_THROW_ON_ERROR)])->select('allowed.*');

        return $connection->query()->from('community_inbox_items as i')->joinSub($access, 'a', function ($join): void {
            $join->on('i.tenant_id', '=', 'a.tenant_id')->on('i.recipient_type', '=', 'a.recipient_type')->whereRaw('COALESCE(i.member_id,i.follower_id)=a.recipient_id');
        })->leftJoin('community_messages as m', 'm.id', '=', 'i.message_id')->leftJoin('community_inbox_reads as r', function ($join): void {
            $join->on('r.item_id', '=', 'i.id')->on('r.reader_key', '=', 'a.reader_key')->whereRaw('r.entry_key=COALESCE(i.message_id,i.id)');
        })->whereIn('i.tenant_id', $this->rollout->organisations()->select('id'))->whereNull('i.withdrawn_at')->where('i.available_at', '<=', CarbonImmutable::now())
            ->whereRaw('(a.consented_at IS NULL OR i.available_at >= a.consented_at)')
            ->where(function ($query): void {
                $query->whereNull('i.message_id')->orWhere(function ($source): void {
                    $source->whereIn('m.status', ['sending', 'completed', 'partial'])->whereNull('m.inbox_withdrawn_at');
                });
            })
            ->when($context !== null, fn ($query) => $query->where('a.context_key', $context));
    }

    private function grouped(Session $session, ?Tenant $tenant = null, ?string $context = null, bool $body = false): Builder
    {
        $query = $this->base($session, $tenant, $context)->selectRaw('i.tenant_id, i.kind, COALESCE(i.message_id,i.id) as entry_key, a.context_key, MIN(i.id::text) as id, MAX(a.label) as label, MIN(i.available_at) as available_at, MAX(COALESCE(m.subject,i.subject)) as subject, CASE WHEN BOOL_AND(r.read_at IS NOT NULL) THEN 1 ELSE 0 END as is_read')->groupByRaw('i.tenant_id,i.kind,COALESCE(i.message_id,i.id),a.context_key');
        if ($body) {
            $query->selectRaw('MAX(COALESCE(m.body,i.body)) as body');
        }

        return $query;
    }

    /** @return array<string,mixed> */
    private function payload(stdClass $row, bool $body = false): array
    {
        $result = ['id' => $row->id, 'context' => $row->context_key, 'kind' => $row->kind, 'subject' => $row->subject, 'available_at' => CarbonImmutable::parse($row->available_at)->toIso8601String(), 'read' => (bool) (int) $row->is_read, 'label' => $row->label];
        if ($body) {
            $result['body'] = $row->body;
            $result['money_url'] = $row->kind === 'announcement' ? null : route('attendee.my', [], false).'?section=money';
        }

        return $result;
    }
}
