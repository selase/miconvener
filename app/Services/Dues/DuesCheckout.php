<?php

declare(strict_types=1);

namespace App\Services\Dues;

use App\Enum\CommercialStreamEnum;
use App\Enum\DuesChargeStatusEnum;
use App\Exceptions\PaymentFailedException;
use App\Models\DuesCharge;
use App\Models\DuesPaymentAttempt;
use App\Models\Member;
use App\Models\Tenant;
use App\Services\Finance\CommercialTerms;
use App\Services\Payment\StripeConnect;
use App\Services\Payment\TenantPaystackResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Starts a Paystack checkout for one or more of a member's open dues charges.
 *
 * The platform's commission is the sum of the per-charge dues fee, the same
 * figure DuesPaymentConfirmer books, so on an organisation's own gateway the
 * split routes exactly what the ledger will record as received.
 */
final class DuesCheckout
{
    public function __construct(
        private readonly TenantPaystackResolver $paystackResolver,
        private readonly StripeConnect $stripeConnect,
        private readonly CommercialTerms $commercialTerms,
    ) {}

    /**
     * Whether a member of this organisation can pay online right now, judged
     * from what is stored so rendering the pay page never calls Paystack.
     */
    public function canPayOnline(Tenant $tenant): bool
    {
        return $this->paystackResolver->isAvailableFor($tenant);
    }

    /**
     * The member's open charges among $chargeIds, oldest period first.
     *
     * @param  array<int, string>  $chargeIds
     * @return Collection<int, DuesCharge>
     *
     * @throws InvalidArgumentException When any id is not an open charge of this member, or the charges mix currencies.
     */
    public function chargesFor(Member $member, array $chargeIds): Collection
    {
        $chargeIds = array_values(array_unique(array_map('strval', $chargeIds)));

        if ($chargeIds === []) {
            throw new InvalidArgumentException('Choose at least one charge to pay.');
        }

        $charges = DuesCharge::query()
            ->where('tenant_id', $member->tenant_id)
            ->where('member_id', $member->id)
            ->where('status', DuesChargeStatusEnum::Open)
            ->whereIn('id', $chargeIds)
            ->orderBy('period_start')
            ->get();

        if ($charges->count() !== count($chargeIds)) {
            throw new InvalidArgumentException('One or more of those charges is no longer open. Refresh the page and try again.');
        }

        if ($charges->pluck('currency')->unique()->count() !== 1) {
            throw new InvalidArgumentException('Charges in different currencies must be paid separately.');
        }

        if ($charges->contains(fn (DuesCharge $charge): bool => $charge->amount <= 0)) {
            throw new InvalidArgumentException('One or more of those charges has nothing to pay.');
        }

        return $charges;
    }

    /**
     * Returns the Paystack authorization URL to send the member to.
     *
     * @param  array<int, string>  $chargeIds
     *
     * @throws InvalidArgumentException When the charges cannot be paid together.
     * @throws RuntimeException When the organisation cannot take this payment online.
     * @throws PaymentFailedException When Paystack refuses the checkout.
     */
    public function start(Member $member, array $chargeIds, string $callbackUrl): string
    {
        $tenant = $member->tenant;

        if ($this->settlesWithStripeOnly($tenant)) {
            throw new RuntimeException('Card payments are not available for this organisation yet.');
        }

        [$charges, $attempt] = DB::connection('landlord')->transaction(function () use ($member, $chargeIds, $tenant): array {
            Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $charges = $this->chargesFor($member, $chargeIds);
            $ids = $charges->pluck('id')->sort()->values()->all();
            $terms = $this->commercialTerms->for($tenant, CommercialStreamEnum::Dues);
            $prior = DuesPaymentAttempt::query()->where('tenant_id', $tenant->id)->where('member_id', $member->id)->where('status', 'open')->get();
            foreach ($prior as $old) {
                if ($old->charge_ids === $ids) {
                    $old->update(['status' => 'superseded']);
                }
            }
            $attempt = DuesPaymentAttempt::query()->create([
                'reference' => 'dues_'.Str::uuid(), 'tenant_id' => $tenant->id, 'member_id' => $member->id,
                'charge_ids' => $ids, 'currency' => $charges->first()->currency, 'expected_amount' => (int) $charges->sum('amount'),
                'rate' => $terms->rate, 'cap_amount' => $terms->capAmount, 'bearer' => $terms->bearer,
                'commissions' => $charges->mapWithKeys(fn (DuesCharge $charge): array => [$charge->id => $this->commercialTerms->commission($terms, (int) $charge->amount)])->all(),
                'terms_source' => $terms->source, 'status' => 'open',
            ]);

            return [$charges, $attempt];
        });
        $currency = (string) $charges->first()->currency;
        $total = (int) $charges->sum('amount');
        $platformShare = array_sum($attempt->commissions);

        $paystack = $this->paystackResolver->gatewayFor($tenant, $platformShare, $currency);

        if ($paystack === null) {
            throw new RuntimeException('Online payments are not currently configured for this organisation.');
        }

        $customerId = $paystack->createCustomer($this->customerEmail($member), $member->name);

        return $paystack->createOneTimeCheckoutSession($customerId, $total, $currency, $callbackUrl, [
            'type' => 'member_dues',
            'dues_attempt_id' => $attempt->id,
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'charge_ids' => $charges->pluck('id')->all(),
            'source' => config('services.paystack.metadata_source'),
            'commission_split' => $paystack->splitSubaccount(),
            'commission_credit_applied' => $paystack->commissionCreditApplied(),
        ], $attempt->reference);
    }

    /**
     * An organisation on its own gateways whose only connected one is Stripe.
     * Dues by Stripe are deferred, so such an organisation cannot take dues
     * online yet. One with Paystack connected as well pays through Paystack.
     */
    private function settlesWithStripeOnly(Tenant $tenant): bool
    {
        return ! $tenant->isPlatformDefaultSettlement()
            && $this->paystackResolver->ownGateway($tenant) === null
            && $this->stripeConnect->connectedGateway($tenant) !== null;
    }

    /**
     * Paystack requires an email for every charge. A member registered by
     * phone alone gets a unique placeholder, as contributions do, so their
     * charges never share a Paystack customer with anyone else.
     */
    private function customerEmail(Member $member): string
    {
        return filled($member->email) ? (string) $member->email : "member+{$member->id}@miconvener.com";
    }
}
