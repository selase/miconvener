<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\DuesChargeStatusEnum;
use App\Enum\LedgerCategoryEnum;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Models\EventPayout;
use App\Models\LedgerAccount;
use App\Models\Member;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DuesReport
{
    public function __construct(private readonly DuesBalance $balance) {}

    /** @return array<string, array<string, int>> */
    public function summary(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $collections = $this->collections($tenant, $from, $to)
            ->selectRaw("currency,
                SUM(CASE WHEN payment_method = 'online' THEN COALESCE(charged_amount, amount) ELSE 0 END) AS online,
                SUM(CASE WHEN payment_method IN ('cash', 'offline') THEN amount ELSE 0 END) AS cash,
                SUM(CASE WHEN payment_method = 'online' THEN COALESCE(gateway_fee_amount, 0) ELSE 0 END) AS fees,
                SUM(CASE WHEN payment_method = 'online' THEN COALESCE(commission_amount, 0) ELSE 0 END) AS commission")
            ->groupBy('currency')->get()->keyBy('currency');
        $arrears = $this->arrears($tenant)->selectRaw('currency, SUM(amount) AS total, COUNT(DISTINCT member_id) AS members')
            ->groupBy('currency')->get()->keyBy('currency');
        $currencies = $collections->keys()->merge($arrears->keys())
            ->merge(LedgerAccount::query()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Dues)->pluck('currency'))
            ->merge(EventPayout::query()->where('tenant_id', $tenant->id)->where('category', LedgerCategoryEnum::Dues)->pluck('currency'))
            ->unique()->sort();
        $summary = [];
        foreach ($currencies as $currency) {
            $row = $collections->get($currency);
            $open = $arrears->get($currency);
            $online = (int) ($row?->getAttribute('online') ?? 0);
            $cash = (int) ($row?->getAttribute('cash') ?? 0);
            $fees = (int) ($row?->getAttribute('fees') ?? 0);
            $commission = (int) ($row?->getAttribute('commission') ?? 0);
            $summary[$currency] = [
                'collected_online_gross' => $online, 'recorded_cash' => $cash,
                'gateway_fees' => $fees, 'commission' => $commission,
                'net_online' => $online - $fees - $commission, 'collected_total' => $online + $cash,
                'available_to_pay_out' => $this->balance->available($tenant, $currency),
                'in_flight' => $this->balance->inFlight($tenant, $currency),
                'arrears_total' => (int) ($open?->getAttribute('total') ?? 0),
                'members_in_arrears' => (int) ($open?->getAttribute('members') ?? 0),
            ];
        }

        return $summary;
    }

    public function exportCollections(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): StreamedResponse
    {
        return response()->streamDownload(function () use ($tenant, $from, $to): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['Member', 'Phone', 'Email', 'Membership type', 'Period start', 'Period end', 'Currency', 'Amount', 'Method', 'Paid at', 'Reference', 'Gateway fee', 'Commission', 'Net'], escape: '');
            $timezone = CommunitySettings::for($tenant)->timezone;
            foreach ($this->collections($tenant, $from, $to)->with(['member', 'membershipType'])->lazyById(500) as $charge) {
                $cash = in_array($charge->payment_method, ['cash', 'offline'], true);
                $gross = $cash ? $charge->amount : ($charge->charged_amount ?? $charge->amount);
                $fee = $cash ? 0 : ($charge->gateway_fee_amount ?? 0);
                $commission = $cash ? 0 : ($charge->commission_amount ?? 0);
                fputcsv($stream, [
                    $this->literal($charge->member?->name), $this->literal($charge->member?->phone), $this->literal($charge->member?->email),
                    $this->literal($charge->membershipType?->name), $charge->period_start->toDateString(), $charge->period_end->toDateString(),
                    $charge->currency, $gross, $charge->payment_method, $charge->paid_at?->timezone($timezone)->format('Y-m-d H:i:s'),
                    $this->literal($charge->payment_reference), $fee, $commission, $gross - $fee - $commission,
                ], escape: '');
            }
            fclose($stream);
        }, 'dues-collections.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportArrears(Tenant $tenant): StreamedResponse
    {
        return response()->streamDownload(function () use ($tenant): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                return;
            }
            fputcsv($stream, ['Member', 'Phone', 'Email', 'Membership types', 'Currency', 'Open charges', 'Total', 'Oldest due date'], escape: '');
            $today = CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->toDateString();
            $overdue = fn (Builder|Relation $query) => $query->where('tenant_id', $tenant->id)->where('status', DuesChargeStatusEnum::Open)->where('due_on', '<', $today);
            foreach (Member::query()->where('tenant_id', $tenant->id)->whereHas('charges', $overdue)
                ->with([
                    'charges' => fn (Relation $query) => $overdue($query)
                        ->select(['member_id', 'membership_type_id', 'currency'])
                        ->selectRaw('SUM(amount) AS amount, COUNT(*) AS charge_count, MIN(due_on) AS due_on')
                        ->groupBy(['member_id', 'membership_type_id', 'currency']),
                    'charges.membershipType',
                ])->lazyById(200) as $member) {
                foreach ($member->charges->groupBy('currency')->sortKeys() as $currency => $charges) {
                    fputcsv($stream, [
                        $this->literal($member->name), $this->literal($member->phone), $this->literal($member->email),
                        $this->literal($charges->map(fn (DuesCharge $charge) => $charge->membershipType?->name)->filter()->unique()->implode('; ')),
                        $currency, (int) $charges->sum('charge_count'), (int) $charges->sum('amount'), $charges->min('due_on')?->toDateString(),
                    ], escape: '');
                }
            }
            fclose($stream);
        }, 'dues-current-arrears.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Builder<DuesCharge> */
    private function collections(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DuesCharge::query()->where('tenant_id', $tenant->id)->where('status', DuesChargeStatusEnum::Paid)->whereBetween('paid_at', [$from, $to]);
    }

    /** @return Builder<DuesCharge> */
    private function arrears(Tenant $tenant): Builder
    {
        return DuesCharge::query()->where('tenant_id', $tenant->id)->where('status', DuesChargeStatusEnum::Open)
            ->where('due_on', '<', CarbonImmutable::now(CommunitySettings::for($tenant)->timezone)->toDateString());
    }

    private function literal(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\s\x00-\x1f]*[=+\-@\t\r\n]/u', $value) === 1 || preg_match('/^[\t\r\n]/', $value) === 1 ? "'".$value : $value;
    }
}
