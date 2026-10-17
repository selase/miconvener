<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\EventContribution;
use App\Models\GivingAdjustment;
use App\Models\GivingGift;
use App\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final class GivingStaffHistory
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,object>
     */
    public function contributions(Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $gifts = GivingGift::withoutGlobalScopes()->where('giving_gifts.tenant_id', $tenant->id)
            ->selectRaw("giving_gifts.id, giving_gifts.fund_id, giving_gifts.amount, giving_gifts.currency, giving_gifts.status, giving_gifts.source::text, giving_gifts.is_anonymous, NULL::text as payer_name, 'gift'::text as source_type, true as can_refund, giving_gifts.created_at");
        $events = EventContribution::withoutGlobalScopes()->join('giving_contribution_links as links', function ($join): void {
            $join->on('links.event_contribution_id', '=', 'event_contributions.id')->on('links.tenant_id', '=', 'event_contributions.tenant_id');
        })->where('event_contributions.tenant_id', $tenant->id)
            ->selectRaw("event_contributions.id, links.fund_id, event_contributions.amount, event_contributions.currency, event_contributions.status, 'online'::text as source, false as is_anonymous, event_contributions.contributor_name as payer_name, 'event_contribution'::text as source_type, (links.category = 'giving' AND links.origin = 'checkout') as can_refund, event_contributions.created_at");
        $query = (new GivingGift)->getConnection()->query()->fromSub($gifts->unionAll($events)->toBase(), 'sources')
            ->join('giving_funds as funds', 'funds.id', '=', 'sources.fund_id')->select(['sources.*', 'funds.name as fund_name']);
        $this->search($query, $filters);

        $page = $query->orderByDesc('sources.created_at')->orderBy('sources.source_type')->orderBy('sources.id')->paginate(25, ['*'], 'gifts_page', (int) ($filters['gifts_page'] ?? 1));
        $records = GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('id', $page->getCollection()->where('source_type', 'gift')->pluck('id'))->get(['id', 'payer_name', 'is_anonymous'])->keyBy('id');
        foreach ($page->items() as $row) {
            if ($row->source_type === 'gift') {
                $record = $records->get($row->id);
                $row->payer_name = $record?->payer_name;
            }
        }

        return $page;
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function adjustments(Tenant $tenant, array $filters): LengthAwarePaginator
    {
        $query = GivingAdjustment::withoutGlobalScopes()->with('fund:id,name,currency')->where('tenant_id', $tenant->id);
        if (($filters['refund_status'] ?? 'all') === 'unresolved') {
            $query->where('kind', 'refund')->whereIn('status', ['requested', 'processing', 'unknown']);
        }
        if (filled($filters['q'] ?? null)) {
            $sourceIds = $this->sourceQuery($tenant, $filters);
            $query->where(fn ($q) => $q->whereIn('gift_id', (clone $sourceIds)->where('source_type', 'gift')->select('sources.id'))->orWhereIn('event_contribution_id', (clone $sourceIds)->where('source_type', 'event_contribution')->select('sources.id')));
        }
        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate(25, ['*'], 'adjustments_page', (int) ($filters['adjustments_page'] ?? 1));

        $data = $page->getCollection()->map(fn (GivingAdjustment $row): array => $this->adjustmentData($row))->all();

        return new LengthAwarePaginator($data, $page->total(), $page->perPage(), $page->currentPage(), ['path' => $page->path(), 'pageName' => 'adjustments_page']);
    }

    /** @return array<string,mixed> */
    private function adjustmentData(GivingAdjustment $row): array
    {
        return ['id' => $row->id, 'principal' => $row->principal, 'kind' => $row->kind, 'status' => $row->status,
            'commission_reversal' => $row->commission_reversal, 'provider_refund_reference' => $row->provider_refund_reference,
            'fund_name' => $row->fund->name, 'currency' => $row->fund->currency];
    }

    /** @param array<string,mixed> $filters */
    private function sourceQuery(Tenant $tenant, array $filters): Builder
    {
        $gifts = GivingGift::withoutGlobalScopes()->where('tenant_id', $tenant->id)->selectRaw("id, fund_id, 'gift'::text as source_type");
        $events = EventContribution::withoutGlobalScopes()->join('giving_contribution_links as links', 'links.event_contribution_id', '=', 'event_contributions.id')->where('event_contributions.tenant_id', $tenant->id)->where('links.tenant_id', $tenant->id)->selectRaw("event_contributions.id, links.fund_id, 'event_contribution'::text as source_type");
        $query = (new GivingGift)->getConnection()->query()->fromSub($gifts->unionAll($events)->toBase(), 'sources')->join('giving_funds as funds', 'funds.id', '=', 'sources.fund_id');
        $this->search($query, $filters);

        return $query;
    }

    /** @param array<string,mixed> $filters */
    private function search(Builder $query, array $filters): void
    {
        if (filled($filters['q'] ?? null)) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_trim($filters['q'])).'%';
            $query->where(fn ($q) => $q->where('funds.name', 'ilike', $term)->orWhereRaw('sources.id::text ILIKE ?', [$term]));
        }
    }
}
