<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enum\DuesChargeStatusEnum;
use App\Exceptions\CommissionSplitUnavailableException;
use App\Exceptions\PaymentFailedException;
use App\Http\Controllers\Billing\WebhookController as BillingWebhookController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Dues\StartDuesCheckoutRequest;
use App\Models\DuesCharge;
use App\Models\Member;
use App\Services\Dues\DuesCheckout;
use App\Services\Dues\DuesPaymentConfirmer;
use App\Services\Payment\TenantPaystackResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * The public page a member pays their dues from, reached by the pay link
 * the organisation sends them. The link's token is the only credential, so
 * every action resolves the member by token within the current organisation
 * and shows nothing but that member's own name and charges.
 */
final class MemberPayController extends Controller
{
    public function __construct(
        private readonly DuesCheckout $checkout,
        private readonly DuesPaymentConfirmer $confirmer,
        private readonly TenantPaystackResolver $paystackResolver,
    ) {}

    public function show(string $subdomain, string $token): Response
    {
        $member = StartDuesCheckoutRequest::memberForToken($token);

        $charges = $member->charges()
            ->where('status', DuesChargeStatusEnum::Open)
            ->orderBy('period_start')
            ->get();

        return Inertia::render('Public/MemberPay', [
            ...$this->pageBase($member),
            'mode' => 'pay',
            'charges' => $charges->map(fn (DuesCharge $charge): array => $this->chargeData($charge))->values(),
            'canPayOnline' => $this->checkout->canPayOnline($member->tenant),
            'checkoutUrl' => route('public.dues.checkout', ['subdomain' => $member->tenant->slug, 'token' => $token]),
        ]);
    }

    public function checkout(StartDuesCheckoutRequest $request, string $subdomain, string $token): SymfonyResponse
    {
        $member = $request->member();

        try {
            $url = $this->checkout->start(
                $member,
                (array) $request->validated('charge_ids'),
                route('public.dues.callback', ['subdomain' => $member->tenant->slug, 'token' => $token]),
            );

            return Inertia::location($url);
        } catch (InvalidArgumentException|RuntimeException $e) {
            /*
             * PaymentFailedException and CommissionSplitUnavailableException
             * are both RuntimeExceptions. Their own messages are technical or
             * speak of events, so the member gets a plain one instead.
             */
            Log::warning('dues.checkout.failed', ['member_id' => $member->id, 'error' => $e->getMessage()]);

            $message = match (true) {
                $e instanceof PaymentFailedException => 'Payment could not be started. Please try again in a moment.',
                $e instanceof CommissionSplitUnavailableException => 'Online payment is not available right now. Please try again later or contact the organisation.',
                default => $e->getMessage(),
            };

            return back()->with('error', $message);
        }
    }

    /**
     * Where Paystack returns the member. The webhook is what normally confirms
     * a payment; this verifies with Paystack and confirms in case the member
     * arrives first. Only a payment whose metadata names this member and
     * organisation, and whose verified amount covers their charges, is
     * confirmed here.
     */
    public function callback(Request $request, string $subdomain, string $token): RedirectResponse
    {
        $member = StartDuesCheckoutRequest::memberForToken($token);
        $tenant = $member->tenant;
        $show = route('public.dues.show', ['subdomain' => $tenant->slug, 'token' => $token]);
        $receipt = route('public.dues.receipt', ['subdomain' => $tenant->slug, 'token' => $token]);
        $reference = (string) ($request->query('reference') ?: $request->query('trxref'));

        if ($reference === '') {
            return redirect($show)->with('error', 'No payment reference was received from the payment provider.');
        }

        try {
            $gateway = $this->paystackResolver->gatewayFor($tenant, 0);

            if ($gateway === null) {
                return redirect($show)->with('info', 'We are finalising your payment.');
            }

            $verification = $gateway->verifyTransaction($reference);
        } catch (Throwable $e) {
            Log::warning('dues.callback.unverified', ['member_id' => $member->id, 'reference' => $reference, 'error' => $e->getMessage()]);

            return redirect($show)->with('info', 'We are finalising your payment.');
        }

        if (($verification['status'] ?? null) !== 'success') {
            return redirect($show)->with('info', 'We are finalising your payment.');
        }

        $metadata = BillingWebhookController::metadata($verification['metadata'] ?? null);
        $verifiedAmount = (int) ($verification['amount'] ?? 0);
        $verifiedCurrency = mb_strtoupper((string) ($verification['currency'] ?? ''));

        if (! $this->verificationMatches($member, $metadata, $verifiedAmount, $verifiedCurrency)) {
            Log::warning('dues.callback.mismatch', [
                'member_id' => $member->id,
                'reference' => $reference,
                'amount' => $verifiedAmount,
                'currency' => $verifiedCurrency,
            ]);

            return redirect($show)->with('error', 'This payment could not be matched to your charges. If money was taken, contact the organisation with your payment reference.');
        }

        $verifiedReference = (string) ($verification['reference'] ?? $reference);

        $this->confirmer->confirm($verifiedReference, [
            'metadata' => $metadata,
            'amount' => $verifiedAmount,
            'fees' => (int) ($verification['fees'] ?? 0),
            'currency' => $verifiedCurrency,
            'provider' => 'paystack',
            'settled_by' => $tenant->isPlatformDefaultSettlement()
                ? DuesPaymentConfirmer::SETTLED_BY_PLATFORM
                : DuesPaymentConfirmer::SETTLED_BY_OWN_GATEWAY,
        ]);

        /*
         * The webhook may have confirmed it already, so success is judged by
         * the charges, not by what this call paid.
         */
        if (! $member->charges()->where('status', DuesChargeStatusEnum::Paid)->where('payment_reference', $verifiedReference)->exists()) {
            return redirect($show)->with('info', 'We are finalising your payment.');
        }

        return redirect($receipt)->with('success', 'Thank you. Your payment has been received.');
    }

    public function receipt(string $subdomain, string $token): Response
    {
        $member = StartDuesCheckoutRequest::memberForToken($token);

        $paid = $member->charges()
            ->where('status', DuesChargeStatusEnum::Paid)
            ->orderByDesc('paid_at')
            ->orderByDesc('period_start')
            ->get();

        return Inertia::render('Public/MemberPay', [
            ...$this->pageBase($member),
            'mode' => 'receipt',
            'paidCharges' => $paid->map(fn (DuesCharge $charge): array => [
                ...$this->chargeData($charge),
                'paid_at' => $charge->paid_at?->toIso8601String(),
                'payment_reference' => $charge->payment_reference,
            ])->values(),
            'payUrl' => route('public.dues.show', ['subdomain' => $member->tenant->slug, 'token' => $token]),
        ]);
    }

    /**
     * The verified payment was started from this member's pay page: its
     * metadata names this member and organisation, it is in the charges'
     * currency and it covers every charge it lists.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function verificationMatches(Member $member, array $metadata, int $amount, string $currency): bool
    {
        if (($metadata['type'] ?? null) !== 'member_dues'
            || (string) ($metadata['tenant_id'] ?? '') !== (string) $member->tenant_id
            || (string) ($metadata['member_id'] ?? '') !== (string) $member->id) {
            return false;
        }

        $chargeIds = array_map('strval', (array) ($metadata['charge_ids'] ?? []));
        $charges = $member->charges()->whereIn('id', $chargeIds)->get();

        if ($chargeIds === [] || $charges->count() !== count(array_unique($chargeIds))) {
            return false;
        }

        return $charges->every(fn (DuesCharge $charge): bool => mb_strtoupper((string) $charge->currency) === $currency)
            && $amount >= (int) $charges->sum('amount');
    }

    /**
     * @return array<string, mixed>
     */
    private function pageBase(Member $member): array
    {
        return [
            'organisation' => [
                'name' => $member->tenant->name,
                'logo_url' => $member->tenant->logo_url,
            ],
            'member' => [
                'name' => $member->name,
                'first_name' => (string) str($member->name)->trim()->before(' '),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeData(DuesCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'period_label' => $this->periodLabel($charge),
            'period_start' => $charge->period_start->toDateString(),
            'period_end' => $charge->period_end->toDateString(),
            'amount' => (int) $charge->amount,
            'currency' => $charge->currency,
            'due_on' => $charge->due_on->toDateString(),
            'is_overdue' => $charge->status === DuesChargeStatusEnum::Open && $charge->due_on->lt(today()),
        ];
    }

    /**
     * "September 2026" for a calendar month, otherwise the date range.
     */
    private function periodLabel(DuesCharge $charge): string
    {
        $start = $charge->period_start;
        $end = $charge->period_end;

        if ($start->isSameDay($start->copy()->startOfMonth()) && $end->isSameDay($start->copy()->endOfMonth())) {
            return $start->format('F Y');
        }

        return $start->format('j M Y').' – '.$end->format('j M Y');
    }
}
