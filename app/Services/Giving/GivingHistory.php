<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Enum\TenantStatusEnum;
use App\Models\EventContribution;
use App\Models\GivingAdjustment;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use App\Models\GivingReceiptNotice;
use App\Models\Tenant;
use App\Services\Community\CommunityHistory;
use App\Services\Community\MemberAccess;
use App\Services\Events\PlatformAttendeeVerification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class GivingHistory
{
    public function __construct(private readonly CommunityHistory $community, private readonly MemberAccess $members) {}

    /** @return list<string> */
    public function tenantIdsForEmail(string $email): array
    {
        $tenants = Tenant::query()->where('status', '!=', TenantStatusEnum::BANNED)->get(['id']);
        $hashes = $tenants->map(fn (Tenant $tenant) => GivingIdentity::emailKey($tenant, $email));

        return GivingGift::withoutGlobalScopes()->whereIn('payer_email_hash', $hashes)->whereIn('status', ['completed', 'refunded'])->pluck('tenant_id')->merge(EventContribution::withoutGlobalScopes()->whereIn('status', ['completed', 'refunded'])->whereRaw('lower(btrim(contributor_email)) = ?', [$email])->whereIn('id', \App\Models\GivingContributionLink::withoutGlobalScopes()->select('event_contribution_id'))->pluck('tenant_id'))->unique()->values()->all();
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,array<string,mixed>>
     */
    public function page(Session $session, array $filters): LengthAwarePaginator
    {
        $page = $this->query($session, $filters)->paginate(20, ['sources.*', 'settings.timezone as tenant_timezone'], 'giving_page', (int) ($filters['giving_page'] ?? 1));
        $rows = $page->getCollection();
        $gifts = GivingGift::withoutGlobalScopes()->with('tenant')->whereIn('id', $rows->where('source_type', 'gift')->pluck('id'))->get()->keyBy('id');
        $events = EventContribution::withoutGlobalScopes()->with('tenant')->whereIn('id', $rows->where('source_type', 'event_contribution')->pluck('id'))->get()->keyBy('id');
        $funds = \App\Models\GivingFund::withoutGlobalScopes()->whereIn('id', $rows->pluck('fund_id'))->get()->keyBy('id');
        $members = $this->community->memberQuery($session)->with('tenant')->get()->keyBy('id');
        $email = PlatformAttendeeVerification::getVerifiedEmail($session);

        $reductions = [];
        foreach (GivingAdjustment::withoutGlobalScopes()->where('status', 'confirmed')->where(fn ($q) => $q->whereIn('gift_id', $gifts->keys())->orWhereIn('event_contribution_id', $events->keys()))->get(['gift_id', 'event_contribution_id', 'principal']) as $adjustment) {
            $key = ($adjustment->gift_id !== null ? 'gift:' : 'event_contribution:').($adjustment->gift_id ?? $adjustment->event_contribution_id);
            $reductions[$key] = ($reductions[$key] ?? 0) + $adjustment->principal;
        }
        foreach (\App\Models\EventLedgerEntry::withoutGlobalScopes()->whereIn('contribution_id', $events->keys())->where('type', \App\Models\EventLedgerEntry::TYPE_REFUND)->get(['contribution_id', 'gross_amount']) as $refund) {
            $key = 'event_contribution:'.$refund->contribution_id;
            $reductions[$key] = ($reductions[$key] ?? 0) + abs($refund->gross_amount);
        }
        $data = [];
        foreach ($rows as $row) {
            $record = $row->source_type === 'gift' ? $gifts[$row->id] : $events[$row->id];
            $payer = $this->payerEmail($record) === $email && $email !== null;
            $member = $row->member_id === null ? null : $members->get($row->member_id);
            $role = $payer ? 'self' : ($member === null ? null : $this->members->role($session, $member));
            abort_unless($role !== null, 404);
            $receipt = $payer && ($row->status === 'completed' || $row->source_type === 'gift' || \App\Models\GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $row->id)->where('origin', 'checkout')->exists());
            $date = $row->occurred_at === null ? null : CarbonImmutable::parse($row->occurred_at)->setTimezone($row->tenant_timezone ?? 'Africa/Accra');
            $data[] = $this->present($record, $funds[$row->fund_id], $role, $member?->name, $receipt, $date, $reductions[$row->source_type.':'.$row->id] ?? 0);
        }

        return new LengthAwarePaginator($data, $page->total(), $page->perPage(), $page->currentPage(), ['path' => $page->path(), 'pageName' => 'giving_page']);
    }

    public function sourceForReceipt(Session $session, Tenant $tenant, string $type, string $id): GivingSource
    {
        abort_if($tenant->status === TenantStatusEnum::BANNED, 404);
        $source = app(GivingSources::class)->find($tenant, $type, $id);
        abort_unless(in_array($source->model->status, ['completed', 'refunded'], true), 404);
        if ($type === 'event_contribution' && $source->model->status === 'refunded') {
            abort_unless(\App\Models\GivingContributionLink::withoutGlobalScopes()->where('event_contribution_id', $id)->where('origin', 'checkout')->where('category', 'giving')->exists(), 404);
        }
        $email = PlatformAttendeeVerification::getVerifiedEmail($session);
        $attempt = GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where($type === 'gift' ? 'gift_id' : 'event_contribution_id', $id)->where('state', 'confirmed')->first();
        abort_unless(($email !== null && $email === $this->payerEmail($source->model)) || ($attempt !== null && $this->ownsCapability($session, $attempt)), 404);

        return $source;
    }

    public function accessKey(Session $session): string
    {
        $members = $this->community->memberQuery($session)->orderBy('id')->get(['id', 'tenant_id', 'access_version'])->map(fn ($member) => [$member->id, $member->access_version, $this->members->role($session, $member)])->all();

        return hash_hmac('sha256', json_encode($members, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function capabilityOwner(Session $session): string
    {
        PlatformAttendeeVerification::getVerifiedEmail($session);
        app(\App\Services\Community\PhoneVerification::class)->verifiedPhone($session);

        return hash_hmac('sha256', json_encode([$session->get(PlatformAttendeeVerification::SESSION_KEY), $session->get(\App\Services\Community\PhoneVerification::SESSION_KEY)], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    public function ownsCapability(Session $session, GivingPaymentAttempt $attempt): bool
    {
        $access = $session->get('giving_checkout_access.'.$attempt->id);

        return is_array($access) && isset($access['identity_owner']) && hash_equals($access['identity_owner'], $this->capabilityOwner($session)) && ($access['expires_at'] ?? 0) > now()->timestamp && $attempt->receipt_expires_at?->isFuture() && hash_equals((string) $attempt->receipt_token_hash, hash('sha256', (string) ($access['token'] ?? '')));
    }

    public function portalUrl(Tenant $tenant): string
    {
        return mb_rtrim((string) config('app.url'), '/').route('attendee.my', [], false).'?'.http_build_query(['organiser' => $tenant->slug, 'section' => 'money', 'tab' => 'giving']);
    }

    /** @return array<string,mixed> */
    public function receiptSnapshot(GivingSource $source): array
    {
        $model = $source->model;

        return ['source_key' => $source->key(), 'receipt_number' => 'GIVING-'.$source->id, 'organisation' => $source->fund->tenant->name, 'fund' => $source->fund->name,
            'amount' => $source->principal, 'currency' => $source->currency, 'payer_name' => $model instanceof GivingGift ? $model->payer_name : $model->contributor_name,
            'payer_email' => $this->payerEmail($model), 'timezone' => \App\Models\CommunitySettings::for($source->fund->tenant)->timezone, 'paid_at' => ($model instanceof GivingGift ? ($model->collected_at ?? $model->paid_at) : $model->paid_at)?->toIso8601String()];
    }

    /** @return array<string,mixed> */
    public function originalReceipt(GivingSource $source): array
    {
        return GivingReceiptNotice::withoutGlobalScopes()->where('tenant_id', $source->fund->tenant_id)->where($source->type === 'gift' ? 'gift_id' : 'event_contribution_id', $source->id)->first()->snapshot ?? $this->receiptSnapshot($source);
    }

    /** @param array<string,mixed> $filters
     * @return Collection<int,array<string,mixed>>
     */
    public function statement(Session $session, array $filters): Collection
    {
        $payments = $this->query($session, $filters)->get(['sources.*', 'settings.timezone as tenant_timezone']);
        $all = $this->query($session, array_diff_key($filters, ['from' => true, 'to' => true]));
        $giftIds = (clone $all)->reorder()->where('source_type', 'gift')->select('sources.id');
        $eventIds = (clone $all)->reorder()->where('source_type', 'event_contribution')->select('sources.id');
        $adjustments = (new GivingAdjustment)->getConnection()->query()->from('giving_adjustments as adjustments')->join('giving_funds as funds', 'funds.id', '=', 'adjustments.fund_id')->leftJoin('community_settings as settings', 'settings.tenant_id', '=', 'adjustments.tenant_id')
            ->where('adjustments.status', 'confirmed')->where(fn ($q) => $q->whereIn('adjustments.gift_id', $giftIds)->orWhereIn('adjustments.event_contribution_id', $eventIds));
        $this->dates($adjustments, 'adjustments.confirmed_at', $filters);
        $changes = $adjustments->get(['adjustments.id', 'adjustments.tenant_id', 'adjustments.fund_id', 'adjustments.principal', 'adjustments.kind', 'adjustments.confirmed_at', 'funds.currency', 'adjustments.gift_id', 'adjustments.event_contribution_id', 'settings.timezone as tenant_timezone']);
        $funds = \App\Models\GivingFund::withoutGlobalScopes()->with('tenant')->whereIn('id', $payments->pluck('fund_id')->merge($changes->pluck('fund_id')))->get()->keyBy('id');
        $giftRecords = GivingGift::withoutGlobalScopes()->whereIn('id', $payments->where('source_type', 'gift')->pluck('id')->merge($changes->pluck('gift_id')->filter()))->get()->keyBy('id');
        $authorisedMembers = $this->community->memberQuery($session)->get()->keyBy('id');
        $email = PlatformAttendeeVerification::getVerifiedEmail($session);
        $context = function (?string $giftId) use ($giftRecords, $authorisedMembers, $email, $session): array {
            if ($giftId === null) {
                return ['access_role' => 'payer', 'member_name' => null];
            }
            $gift = $giftRecords->get($giftId);
            abort_unless($gift !== null, 404);
            if ($email !== null && $this->payerEmail($gift) === $email) {
                return ['access_role' => 'payer', 'member_name' => null];
            }
            $member = $authorisedMembers->get($gift->member_id);
            $role = $member === null ? null : $this->members->role($session, $member);
            abort_unless($member !== null && $role !== null, 404);

            return ['access_role' => $role, 'member_name' => $member->name];
        };
        $rows = $payments->map(fn (object $row): array => $this->statementRow($row->source_type.':'.$row->id, $funds[$row->fund_id], $row->kind, $row->occurred_at === null ? 'Unknown' : CarbonImmutable::parse($row->occurred_at)->setTimezone($row->tenant_timezone ?? 'Africa/Accra')->format('Y-m-d H:i'), (int) $row->amount, $context($row->source_type === 'gift' ? $row->id : null)));

        $combined = $rows->concat($changes->map(fn (object $row): array => $this->statementRow('adjustment:'.$row->id, $funds[$row->fund_id], $row->kind, CarbonImmutable::parse($row->confirmed_at)->setTimezone($row->tenant_timezone ?? 'Africa/Accra')->format('Y-m-d H:i'), -(int) $row->principal, $context($row->gift_id))));
        $undated = (clone $all)->reorder()->where('source_type', 'event_contribution')->where('sources.status', 'refunded')->get(['sources.id', 'sources.fund_id']);
        $known = \App\Models\EventLedgerEntry::withoutGlobalScopes()->whereIn('contribution_id', $undated->pluck('id'))->where('type', \App\Models\EventLedgerEntry::TYPE_REFUND)->pluck('contribution_id')->merge(GivingAdjustment::withoutGlobalScopes()->whereIn('event_contribution_id', $undated->pluck('id'))->where('status', 'confirmed')->pluck('event_contribution_id'));
        $records = EventContribution::withoutGlobalScopes()->whereIn('id', $undated->pluck('id')->diff($known))->get()->keyBy('id');
        $extraFunds = \App\Models\GivingFund::withoutGlobalScopes()->with('tenant')->whereIn('id', $undated->pluck('fund_id'))->get()->keyBy('id');
        foreach ($undated as $row) {
            $source = $records->get($row->id);
            if ($source === null) {
                continue;
            }$note = $this->statementRow('undated:'.$source->id, $extraFunds[$row->fund_id], 'legacy_refund_undated', 'Unknown', 0, ['access_role' => 'payer', 'member_name' => null]);
            $note['undated_amount'] = $source->amount;
            $combined->push($note);
        }

        return $combined->sortBy('date')->values();
    }

    /** @return array<string,mixed> */
    private function present(GivingGift|EventContribution $record, \App\Models\GivingFund $fund, string $role, ?string $memberName, bool $receipt, ?CarbonImmutable $date, int $reduced): array
    {
        $type = $record instanceof GivingGift ? 'gift' : 'event_contribution';

        $reduced = $record->status === 'refunded' ? max($reduced, $record->amount) : $reduced;

        return ['key' => $type.':'.$record->id, 'id' => $record->id, 'source_type' => $type, 'amount' => $record->amount, 'currency' => $record->currency, 'status' => $record->status,
            'adjusted_amount' => $reduced, 'net_contribution' => max(0, $record->amount - $reduced), 'source' => $record instanceof GivingGift ? $record->source : 'online', 'organisation' => $record->tenant->name, 'tenant_slug' => $record->tenant->slug, 'fund' => $fund->name,
            'date' => $date?->toIso8601String(), 'access_role' => $role, 'member_name' => $memberName,
            'receipt_url' => $receipt ? ($type === 'event_contribution' ? route('attendee.my.contributions.receipt', ['contribution' => $record->id]) : route('attendee.my.giving.receipt', ['type' => 'gift', 'source' => $record->id])) : null];
    }

    /** @param array{access_role:string,member_name:?string} $context
     * @return array<string,mixed> */
    private function statementRow(string $key, \App\Models\GivingFund $fund, string $kind, string $date, int $amount, array $context): array
    {
        return ['key' => $key, 'organisation' => $fund->tenant->name, 'fund' => $fund->name, 'currency' => $fund->currency, 'kind' => $kind, 'date' => $date, 'amount' => $amount, 'undated_amount' => 0, ...$context];
    }

    /** One SQL union with explicit typed identities; pagination never combines two already-paginated lists.
     * @param  array<string,mixed>  $filters
     */
    private function query(Session $session, array $filters): Builder
    {
        $email = PlatformAttendeeVerification::getVerifiedEmail($session);
        $hashes = $email === null ? [] : Tenant::query()->where('status', '!=', TenantStatusEnum::BANNED)->get(['id'])->map(fn (Tenant $tenant) => GivingIdentity::emailKey($tenant, $email))->all();
        $memberIds = $this->community->memberQuery($session)->select('id');
        $gifts = GivingGift::withoutGlobalScopes()->whereIn('status', ['completed', 'refunded'])->whereHas('tenant', fn ($q) => $q->where('status', '!=', TenantStatusEnum::BANNED))
            ->where(fn ($q) => $q->whereIn('payer_email_hash', $hashes)->orWhereIn('member_id', $memberIds))
            ->selectRaw("giving_gifts.id, giving_gifts.tenant_id, giving_gifts.fund_id, giving_gifts.member_id, giving_gifts.amount, giving_gifts.currency, giving_gifts.status, 'gift'::text as source_type, giving_gifts.source::text as kind, COALESCE(giving_gifts.collected_at,giving_gifts.paid_at) as occurred_at, COALESCE(giving_gifts.collected_at,giving_gifts.paid_at,giving_gifts.created_at) as sort_at");
        if (isset($filters['member_id'])) {
            $gifts->where('member_id', $filters['member_id']);
        }
        $events = EventContribution::withoutGlobalScopes()->join('giving_contribution_links as links', fn ($join) => $join->on('links.event_contribution_id', '=', 'event_contributions.id')->on('links.tenant_id', '=', 'event_contributions.tenant_id'))
            ->whereIn('event_contributions.status', ['completed', 'refunded'])->whereHas('tenant', fn ($q) => $q->where('status', '!=', TenantStatusEnum::BANNED))
            ->whereRaw('lower(btrim(event_contributions.contributor_email)) = ?', [$email ?? ''])
            ->selectRaw("event_contributions.id, event_contributions.tenant_id, links.fund_id, NULL::uuid as member_id, event_contributions.amount, event_contributions.currency, event_contributions.status, 'event_contribution'::text as source_type, 'online'::text as kind, event_contributions.paid_at as occurred_at, COALESCE(event_contributions.paid_at,event_contributions.created_at) as sort_at");
        if ($email === null || isset($filters['member_id'])) {
            $events->whereRaw('1=0');
        }
        $query = (new GivingGift)->getConnection()->query()->fromSub($gifts->toBase()->unionAll($events->toBase()), 'sources')
            ->leftJoin('community_settings as settings', 'settings.tenant_id', '=', 'sources.tenant_id');
        if (isset($filters['tenant_slug'])) {
            $query->whereIn('sources.tenant_id', Tenant::query()->where('slug', $filters['tenant_slug'])->select('id'));
        }
        if (isset($filters['currency'])) {
            $query->where('sources.currency', $filters['currency']);
        }
        foreach (['source_type', 'source'] as $filter) {
            if (isset($filters[$filter])) {
                $query->where($filter === 'source' ? 'sources.kind' : 'sources.source_type', $filters[$filter]);
            }
        }
        $this->dates($query, 'sources.occurred_at', $filters);

        return $query->orderByDesc('sources.sort_at')->orderBy('sources.source_type')->orderBy('sources.id');
    }

    /** @param array<string,mixed> $filters */
    private function dates(Builder $query, string $column, array $filters): void
    {
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (isset($filters[$key])) {
                $query->whereRaw("($column AT TIME ZONE COALESCE(settings.timezone, ?))::date $operator ?", ['Africa/Accra', $filters[$key]]);
            }
        }
    }

    private function payerEmail(GivingGift|EventContribution $model): ?string
    {
        $email = $model instanceof GivingGift ? $model->payer_email : $model->contributor_email;

        return filled($email) ? PlatformAttendeeVerification::normalise($email) : null;
    }
}
