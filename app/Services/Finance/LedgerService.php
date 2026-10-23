<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enum\LedgerCategoryEnum;
use App\Models\DuesCharge;
use App\Models\Event;
use App\Models\EventContribution;
use App\Models\EventLedgerEntry;
use App\Models\EventPayout;
use App\Models\EventRegistration;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class LedgerService
{
    /**
     * Ensures all standard chart-of-accounts exist for the tenant & event in this currency.
     *
     * @return array<string, LedgerAccount>
     */
    public function ensureAccountsExist(Tenant $tenant, ?Event $event = null, string $currency = 'GHS', ?LedgerCategoryEnum $category = null): array
    {
        $category = $this->resolveCategory($event, $category);

        $definitions = [
            LedgerAccount::CODE_GATEWAY_CLEARING => [
                'name' => 'Payment Gateway Clearing',
                'type' => LedgerAccount::TYPE_ASSET,
            ],
            LedgerAccount::CODE_BANK => [
                'name' => 'Cash / Bank Account',
                'type' => LedgerAccount::TYPE_ASSET,
            ],
            LedgerAccount::CODE_ORGANIZER_PAYABLE => [
                'name' => 'Organizer Payable (Available Balance)',
                'type' => LedgerAccount::TYPE_LIABILITY,
            ],
            LedgerAccount::CODE_HOLDBACK_RESERVE => [
                'name' => 'Holdback Reserve (Escrow)',
                'type' => LedgerAccount::TYPE_LIABILITY,
            ],
            LedgerAccount::CODE_PLATFORM_REVENUE => [
                'name' => 'Platform Commission Revenue',
                'type' => LedgerAccount::TYPE_REVENUE,
            ],
            LedgerAccount::CODE_GATEWAY_FEES => [
                'name' => 'Payment Processing Fees',
                'type' => LedgerAccount::TYPE_EXPENSE,
            ],
        ];

        if ($category === LedgerCategoryEnum::Commerce) {
            $definitions[LedgerAccount::CODE_BUYER_REFUNDS] = ['name' => 'Buyer Refund Liability', 'type' => LedgerAccount::TYPE_LIABILITY];
        }

        $accounts = [];
        $eventId = $event?->id;

        foreach ($definitions as $code => $def) {
            $account = LedgerAccount::on('landlord')->firstOrCreate([
                'tenant_id' => $tenant->id,
                'event_id' => $eventId,
                'category' => $category->value,
                'code' => $code,
                'currency' => $currency,
            ], [
                'name' => $def['name'],
                'type' => $def['type'],
                'is_active' => true,
            ]);

            $accounts[$code] = $account;
        }

        return $accounts;
    }

    /**
     * Posts a double-entry balanced transaction.
     * Enforces the fundamental invariant: Sum(Debits) === Sum(Credits).
     *
     * @param  array<int, array{code: string, direction: string, amount: int, description?: string}>  $entries
     */
    public function postTransaction(
        Tenant $tenant,
        ?Event $event,
        string $transactionType,
        string $description,
        string $reference,
        array $entries,
        ?string $userId = null,
        string $currency = 'GHS',
        ?LedgerCategoryEnum $category = null,
        ?\App\Models\GivingFund $givingFund = null
    ): LedgerTransaction {
        $category = $this->resolveCategory($event, $category);
        if ($givingFund !== null && ($givingFund->tenant_id !== $tenant->id || $category !== LedgerCategoryEnum::Giving)) {
            throw new InvalidArgumentException('Giving fund attribution must match the tenant and Giving category.');
        }

        if (empty($entries)) {
            throw new InvalidArgumentException('Cannot post an empty ledger transaction.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $e) {
            if (($e['amount'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Ledger entry amounts must be positive integers.');
            }

            if ($e['direction'] === LedgerEntry::DIRECTION_DEBIT) {
                $totalDebits += (int) $e['amount'];
            } elseif ($e['direction'] === LedgerEntry::DIRECTION_CREDIT) {
                $totalCredits += (int) $e['amount'];
            } else {
                throw new InvalidArgumentException("Invalid ledger direction: {$e['direction']}. Must be debit or credit.");
            }
        }

        if ($totalDebits !== $totalCredits) {
            throw new InvalidArgumentException(
                "Unbalanced ledger transaction! Debits ({$totalDebits}) must exactly equal Credits ({$totalCredits})."
            );
        }

        /*
         * A reference names one real movement of money, so the same reference
         * arriving twice is a redelivery rather than a second event. Return
         * what was already posted: a webhook must not get an exception it
         * would answer with a 500, which only earns another retry.
         */
        $alreadyPosted = $this->findPostedTransaction($tenant, $reference, $transactionType);

        if ($alreadyPosted) {
            return $alreadyPosted;
        }

        $accounts = $this->ensureAccountsExist($tenant, $event, $currency, $category);

        try {
            return DB::connection('landlord')->transaction(function () use ($tenant, $event, $transactionType, $description, $reference, $entries, $userId, $currency, $accounts, $category, $givingFund): LedgerTransaction {
                $transaction = LedgerTransaction::create([
                    'tenant_id' => $tenant->id,
                    'event_id' => $event?->id,
                    'category' => $category,
                    'giving_fund_id' => $givingFund?->id,
                    'reference' => $reference,
                    'description' => $description,
                    'transaction_type' => $transactionType,
                    'posted_at' => now(),
                    'created_by' => $userId,
                ]);

                foreach ($entries as $entryData) {
                    $account = $accounts[$entryData['code']] ?? null;
                    if (! $account) {
                        throw new InvalidArgumentException("Unknown account code: {$entryData['code']}");
                    }

                    LedgerEntry::create([
                        'transaction_id' => $transaction->id,
                        'account_id' => $account->id,
                        'direction' => $entryData['direction'],
                        'amount' => (int) $entryData['amount'],
                        'currency' => $currency,
                        'description' => $entryData['description'] ?? null,
                    ]);
                }

                return $transaction;
            });
        } catch (UniqueConstraintViolationException $e) {
            /*
             * Two deliveries raced past the check above. The one that lost is
             * the duplicate, so hand back the row the winner wrote.
             */
            $posted = $this->findPostedTransaction($tenant, $reference, $transactionType);

            if (! $posted) {
                throw $e;
            }

            return $posted;
        }
    }

    /**
     * Record a ticket sale:
     * Debit:  Payment Gateway Clearing (gross)
     * Credit: Organizer Payable (net to organizer)
     * Credit: Platform Revenue (commission)
     */
    public function recordTicketSale(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        ?string $providerReference = null
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null; // Free tickets do not move financial cash
        }

        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        /*
         * Paystack nets its collection fee out before the money ever reaches
         * the platform, so debiting the gross would book cash that never
         * arrived and leave the organizer payable permanently unclearable.
         */
        $cashReceived = $grossAmount - $gatewayFee;
        $netAmount = $cashReceived - $platformFee;

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $cashReceived,
                'description' => "Ticket payment net of processing fee: {$registration->full_name} ({$registration->email})",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $netAmount,
                'description' => 'Net ticket revenue payable to organizer',
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => 'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
            ];
        }

        $transaction = $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_TICKET_SALE,
            "Ticket Sale: {$event->name} - {$registration->full_name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'provider_reference' => $providerReference ?? $reference,
        ], [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $platformFee,
            'net_amount' => $netAmount,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * Reverse platform commission debited for an offline ticket sale upon cancellation.
     * When an offline ticket is cancelled:
     * - The organizer handles any attendee cash/MoMo refund directly.
     * - The platform reverses the commission receivable that was charged against the organizer:
     *     Debit:  Platform Revenue (reversing commission earned)
     *     Credit: Organizer Payable (restoring the debited payable)
     * - Flat EventLedgerEntry:
     *     type: TYPE_REFUND
     *     provider: 'offline'
     *     gross_amount: $chargeEntry->gross_amount
     *     gateway_fee_amount: 0
     *     commission_amount: $chargeEntry->commission_amount
     *     net_amount: $chargeEntry->commission_amount
     */
    public function reverseOfflineTicketCommission(
        Event $event,
        EventRegistration $registration
    ): ?LedgerTransaction {
        $reference = $registration->payment_reference ?: 'OFFLINE-'.$registration->id;
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $chargeEntry = EventLedgerEntry::where('tenant_id', $tenant->id)
            ->where('type', EventLedgerEntry::TYPE_CHARGE)
            ->where('provider_reference', $reference)
            ->first();

        if (! $chargeEntry) {
            return null;
        }

        $commission = (int) $chargeEntry->commission_amount;
        $refundRef = 'REF_'.$reference;
        $transaction = null;

        if ($commission > 0) {
            $entries = [
                [
                    'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                    'direction' => LedgerEntry::DIRECTION_DEBIT,
                    'amount' => $commission,
                    'description' => "Platform commission reversal on offline ticket cancellation: {$registration->full_name}",
                ],
                [
                    'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                    'direction' => LedgerEntry::DIRECTION_CREDIT,
                    'amount' => $commission,
                    'description' => "Restoring commission receivable for cancelled offline ticket: {$registration->email}",
                ],
            ];

            $transaction = $this->postTransaction(
                $tenant,
                $event,
                LedgerTransaction::TYPE_REFUND,
                "Offline Ticket Reversal: {$event->name} - {$registration->full_name}",
                $refundRef,
                $entries,
                null,
                $event->currency ?: 'GHS'
            );
        }

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_REFUND,
            'provider_reference' => $refundRef,
        ], [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'gross_amount' => (int) $chargeEntry->gross_amount,
            'gateway_fee_amount' => 0,
            'commission_amount' => $commission,
            'net_amount' => $commission,
            'currency' => $event->currency ?: 'GHS',
            'provider' => 'offline',
        ]);

        return $transaction;
    }

    /**
     * Record an offline direct ticket sale (bank transfer, direct MoMo, or cash on-site):
     * The attendee paid the organizer directly outside the platform.
     * The platform is owed the platform commission (if any).
     * If platformFee > 0:
     *   Debit:  Organizer Payable (debited to recover commission from future payouts)
     *   Credit: Platform Revenue (platform commission earned)
     * Flat EventLedgerEntry:
     *   type: TYPE_CHARGE
     *   provider: 'offline'
     *   gross_amount: $grossAmount
     *   gateway_fee_amount: 0
     *   commission_amount: $platformFee
     *   net_amount: -$platformFee
     */
    public function recordOfflineTicketSale(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference,
        string $provider = 'offline',
        ?string $providerReference = null
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $transaction = null;

        if ($platformFee > 0) {
            $entries = [
                [
                    'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                    'direction' => LedgerEntry::DIRECTION_DEBIT,
                    'amount' => $platformFee,
                    'description' => "Commission receivable for offline ticket: {$registration->full_name} ({$registration->email})",
                ],
                [
                    'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                    'direction' => LedgerEntry::DIRECTION_CREDIT,
                    'amount' => $platformFee,
                    'description' => 'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%) on offline ticket sale',
                ],
            ];

            $transaction = $this->postTransaction(
                $tenant,
                $event,
                LedgerTransaction::TYPE_OFFLINE_TICKET_SALE,
                "Offline Ticket Commission: {$event->name} - {$registration->full_name}",
                $reference,
                $entries,
                null,
                $event->currency ?: 'GHS'
            );
        }

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'provider_reference' => $providerReference ?? $reference,
        ], [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => 0,
            'commission_amount' => $platformFee,
            'net_amount' => -$platformFee,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * Record a voluntary event contribution / donation:
     * Debit:  Payment Gateway Clearing (gross collected less gateway processing fee)
     * Credit: Organizer Payable (net to organizer)
     * Credit: Platform Revenue (platform commission fee, if any)
     */
    public function recordContribution(
        Event $event,
        EventContribution $contribution,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        ?string $providerReference = null
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $cashReceived = $grossAmount - $gatewayFee;
        $netAmount = $cashReceived - $platformFee;
        $displayName = $contribution->displayName();

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $cashReceived,
                'description' => "Contribution payment net of processing fee: {$displayName}",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $netAmount,
                'description' => 'Net contribution revenue payable to organizer',
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => 'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
            ];
        }

        $transaction = $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_CONTRIBUTION,
            "Contribution: {$event->name} - {$displayName}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'provider_reference' => $providerReference ?? $reference,
        ], [
            'event_id' => $event->id,
            'contribution_id' => $contribution->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $platformFee,
            'net_amount' => $netAmount,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /** @param array<string, mixed> $verifiedAmounts */
    public function recordStoreSale(\App\Models\StoreOrder $order, \App\Models\StorePaymentAttempt $attempt, array $verifiedAmounts): LedgerTransaction
    {
        $capture = \App\Models\StorePaymentObservation::query()->where('attempt_id', $attempt->id)->where('order_id', $order->id)->findOrFail($verifiedAmounts['observation_id'] ?? '');
        if ($capture->integrity_errors !== [] || ! $capture->account_matches || ! $capture->reference_matches || $attempt->order_id !== $order->id
            || $attempt->tenant_id !== $order->tenant_id || $capture->amount_pesewas !== $order->charged_pesewas
            || $capture->gateway_fee_pesewas === null || $capture->gateway_fee_pesewas + $order->platform_fee_pesewas >= $order->charged_pesewas) {
            throw new InvalidArgumentException('Store sale requires matching original-account captured money.');
        }
        $net = $order->charged_pesewas - $capture->gateway_fee_pesewas;
        $entries = [
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $net],
            ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $net - $order->platform_fee_pesewas],
        ];
        if ($order->platform_fee_pesewas > 0) {
            $entries[] = ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $order->platform_fee_pesewas];
        }
        $transaction = $this->postTransaction($order->tenant, null, 'commerce_sale', 'Store order '.$order->id, $attempt->reference,
            array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] > 0)), null, $order->currency, LedgerCategoryEnum::Commerce);
        if ($transaction->category !== LedgerCategoryEnum::Commerce || $transaction->event_id !== null) {
            throw new InvalidArgumentException('Store reference conflicts with another ledger stream.');
        }

        return $transaction;
    }

    public function recordStoreCaptureReview(\App\Models\StorePaymentObservation $capture): ?LedgerTransaction
    {
        if (! $capture->account_matches || ! $capture->reference_matches || $capture->currency !== 'GHS' || $capture->amount_pesewas === null
            || $capture->amount_pesewas < 1 || $capture->gateway_fee_pesewas === null || $capture->gateway_fee_pesewas > $capture->amount_pesewas) {
            return null;
        }
        $entries = [
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $capture->amount_pesewas - $capture->gateway_fee_pesewas],
            ['code' => LedgerAccount::CODE_GATEWAY_FEES, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $capture->gateway_fee_pesewas],
            ['code' => LedgerAccount::CODE_BUYER_REFUNDS, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $capture->amount_pesewas],
        ];

        return $this->postTransaction($capture->order->tenant, null, 'commerce_capture_review', 'Captured payment requiring review: '.$capture->order_id,
            $capture->reference, array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] > 0)), null, 'GHS', LedgerCategoryEnum::Commerce);
    }

    /** @param array{gateway_fee:int, commission_received:int, credit_used:int} $verifiedAmounts */
    public function recordGivingPayment(Tenant $tenant, \App\Services\Giving\GivingSource $source, \App\Models\GivingPaymentAttempt $attempt, array $verifiedAmounts): ?LedgerTransaction
    {
        if ($source->fund->tenant_id !== $tenant->id || $source->fund->id !== $attempt->fund_id || $source->principal !== $attempt->amount || $source->currency !== $attempt->currency || $attempt->category !== 'giving' || ($source->model instanceof \App\Models\GivingGift && $source->model->source !== 'online')) {
            throw new InvalidArgumentException('Giving posting must match its online source and frozen attempt.');
        }
        $fees = $verifiedAmounts['gateway_fee'];
        $commission = $attempt->commission_amount;
        if ($fees < 0 || $fees + $commission > $attempt->amount) {
            throw new InvalidArgumentException('Giving processing fees and commission exceed the payment.');
        }
        if ($attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            return $this->recordOwnGatewaySale($tenant, null, 'Giving (own gateway): '.$source->key(), $attempt->reference,
                $attempt->amount, $commission, $fees, $attempt->provider, $verifiedAmounts['commission_received'], $verifiedAmounts['credit_used'],
                [], $attempt->provider_reference, LedgerTransaction::TYPE_GIVING_PAYMENT, LedgerCategoryEnum::Giving,
                'Recorded Giving commission', $attempt->currency, $source->fund);
        }
        $entries = [
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $attempt->amount - $fees],
            ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $attempt->amount - $fees - $commission],
        ];
        if ($commission > 0) {
            $entries[] = ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $commission];
        }

        return $this->postTransaction($tenant, null, LedgerTransaction::TYPE_GIVING_PAYMENT, 'Giving: '.$source->key(), $attempt->reference,
            $entries, null, $attempt->currency, LedgerCategoryEnum::Giving, $source->fund);
    }

    public function recordGivingRefund(Tenant $tenant, \App\Services\Giving\GivingSource $source, \App\Models\GivingAdjustment $adjustment): ?LedgerTransaction
    {
        $attempt = \App\Models\GivingPaymentAttempt::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('fund_id', $source->fund->id)->where($source->type === 'gift' ? 'gift_id' : 'event_contribution_id', $source->id)->where('state', 'confirmed')->firstOrFail();
        if ($adjustment->tenant_id !== $tenant->id || $adjustment->fund_id !== $source->fund->id || $adjustment->gift_id !== $source->columns()['gift_id'] || $adjustment->event_contribution_id !== $source->columns()['event_contribution_id']) {
            throw new InvalidArgumentException('Refund must match the original Giving source.');
        }
        $reversal = $adjustment->commission_reversal;
        if ($attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT) {
            $net = $adjustment->principal - $reversal - $adjustment->gateway_credit;
            $entries = [
                ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => max(0, $net)],
                ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $reversal],
                ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $adjustment->principal - $adjustment->gateway_credit],
            ];
            if ($net < 0) {
                $entries[] = ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => -$net];
            }
        } else {
            if ($reversal === 0 && $adjustment->commission_returned === 0) {
                return null;
            }
            $entries = [
                ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $reversal],
                ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $adjustment->commission_returned],
                ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => max(0, $reversal - $adjustment->commission_returned)],
            ];
        }
        if ($attempt->settlement_mode !== Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT && $adjustment->commission_returned > $reversal) {
            $entries[] = ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $adjustment->commission_returned - $reversal];
        }
        $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0));
        $transaction = $this->postTransaction($tenant, null, LedgerTransaction::TYPE_REFUND, 'Giving refund: '.$source->key(), 'giving_refund_'.$adjustment->id, $entries, null, $source->currency, LedgerCategoryEnum::Giving, $source->fund);
        if ($transaction->wasRecentlyCreated && $adjustment->credit_restored > 0) {
            app(CommissionCredit::class)->grant($tenant, $source->currency, $adjustment->credit_restored);
        }

        return $transaction;
    }

    /** Full member-ticket refund using the original captured amounts, never current commercial terms. */
    public function recordMemberBenefitRefund(\App\Models\EventMemberBenefitAttempt $attempt, int $gatewayCredit, int $commissionReturned): ?LedgerTransaction
    {
        $registration = $attempt->registration;
        $event = Event::withoutGlobalScopes()->where('tenant_id', $attempt->tenant_id)->findOrFail($registration->event_id);
        $tenant = $attempt->tenant;
        if ($registration->tenant_id !== $attempt->tenant_id || $gatewayCredit < 0 || $gatewayCredit > (int) $attempt->gateway_fee_amount || $commissionReturned < 0 || $commissionReturned > $attempt->platform_fee_amount) {
            throw new InvalidArgumentException('Refund amounts and ownership must match the original member payment.');
        }
        $commission = $attempt->platform_fee_amount;
        $reference = 'member_refund_'.$attempt->id;
        $platform = $attempt->settlement_mode === Tenant::SETTLEMENT_MODE_PLATFORM_DEFAULT;
        $entries = $platform ? [
            ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $attempt->charged_amount - $commission - $gatewayCredit],
            ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $commission],
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $attempt->charged_amount - $gatewayCredit],
        ] : [
            ['code' => LedgerAccount::CODE_PLATFORM_REVENUE, 'direction' => LedgerEntry::DIRECTION_DEBIT, 'amount' => $commission],
            ['code' => LedgerAccount::CODE_GATEWAY_CLEARING, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $commissionReturned],
            ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $commission - $commissionReturned],
        ];
        if ($platform && $attempt->charged_amount - $commission - $gatewayCredit < 0) {
            $entries[0]['amount'] = 0;
            $entries[] = ['code' => LedgerAccount::CODE_ORGANIZER_PAYABLE, 'direction' => LedgerEntry::DIRECTION_CREDIT, 'amount' => $commission + $gatewayCredit - $attempt->charged_amount];
        }
        $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['amount'] !== 0));
        $transaction = $entries !== [] ? $this->postTransaction($tenant, $event, LedgerTransaction::TYPE_REFUND, 'Member ticket refund', $reference, $entries, null, $attempt->currency) : null;
        if ($transaction?->wasRecentlyCreated && ! $platform) {
            $credit = (int) ($attempt->commission_settlement['actual_credit_used'] ?? 0);
            if ($attempt->provider === 'paystack') {
                $credit += (int) ($attempt->commission_settlement['actual_received'] ?? 0);
            }
            app(CommissionCredit::class)->grant($tenant, $attempt->currency, min($commission - $commissionReturned, $credit));
        }
        EventLedgerEntry::firstOrCreate(['tenant_id' => $tenant->id, 'type' => EventLedgerEntry::TYPE_REFUND, 'provider_reference' => $reference],
            ['event_id' => $event->id, 'registration_id' => $registration->id, 'gross_amount' => $attempt->charged_amount,
                'gateway_fee_amount' => $gatewayCredit, 'commission_amount' => $commission,
                'net_amount' => -($attempt->charged_amount - $commission - $gatewayCredit), 'currency' => $attempt->currency, 'provider' => $attempt->provider]);

        return $transaction;
    }

    public function recordGivingPayout(Tenant $tenant, EventPayout $payout, string $reference, int $transferFee): LedgerTransaction
    {
        if ($payout->tenant_id !== $tenant->id || $payout->category !== LedgerCategoryEnum::Giving || $payout->event_id !== null) {
            throw new InvalidArgumentException('Payout must belong to the organisation Giving pool.');
        }

        return $this->postTransaction($tenant, null, LedgerTransaction::TYPE_PAYOUT, 'Giving payout', $reference,
            $this->payoutEntries($payout->amount, $transferFee, false), null, $payout->currency, LedgerCategoryEnum::Giving);
    }

    /**
     * Record a dues payment collected through the platform's gateway, entry
     * for entry as a contribution, in the dues category with no event:
     * Debit:  Payment Gateway Clearing (gross collected less gateway processing fee)
     * Credit: Organizer Payable (net to the organisation)
     * Credit: Platform Revenue (dues commission, if any)
     * No EventLedgerEntry is written; the charge row carries the figures.
     */
    public function recordDuesPayment(
        Tenant $tenant,
        DuesCharge $charge,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }

        $alreadyPosted = $this->findPostedTransaction($tenant, $reference, LedgerTransaction::TYPE_DUES_PAYMENT);

        if ($alreadyPosted) {
            return $alreadyPosted;
        }

        $cashReceived = $grossAmount - $gatewayFee;
        $netAmount = $cashReceived - $platformFee;
        $memberName = $charge->member->name;
        $period = $charge->period_start->toDateString();

        $entries = [
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $cashReceived,
                'description' => "Dues payment net of processing fee: {$memberName}",
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $netAmount,
                'description' => 'Net dues payable to the organisation',
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => 'Dues commission ('.config('services.dues.platform_fee_percentage').'%)',
            ];
        }

        return $this->postTransaction(
            $tenant,
            null,
            LedgerTransaction::TYPE_DUES_PAYMENT,
            "Dues: {$memberName} ({$period})",
            $reference,
            $entries,
            null,
            $charge->currency ?: 'GHS',
            LedgerCategoryEnum::Dues
        );
    }

    /**
     * Record a ticket refund:
     * Debit:  Organizer Payable (net amount deducted)
     * Debit:  Platform Revenue (platform fee reversed, if any)
     * Credit: Payment Gateway Clearing (gross refunded to buyer)
     */
    public function recordRefund(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        ?string $providerReference = null
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        // Paystack does not return its collection fee on a refund, so the
        // cash reversed is the cash that originally arrived.
        $cashReceived = $grossAmount - $gatewayFee;
        $netAmount = $cashReceived - $platformFee;

        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $netAmount,
                'description' => "Refund ticket: {$registration->full_name}",
            ],
            [
                'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $cashReceived,
                'description' => "Refund disbursed to customer: {$registration->email}",
            ],
        ];

        if ($platformFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $platformFee,
                'description' => 'Platform fee reversal on refund',
            ];
        }

        $transaction = $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_REFUND,
            "Refund: {$event->name} - {$registration->full_name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_REFUND,
            'provider_reference' => $providerReference ?? $reference,
        ], [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            /*
             * Only the net is signed. The gross, the processing fee and the
             * commission restate the original charge so the settlement
             * statement can show what is being reversed.
             */
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $platformFee,
            'net_amount' => -$netAmount,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * Record a ticket sold on the organizer's own gateway. The organizer
     * holds the money, so the platform's books carry only its commission, which
     * is Credit Platform Revenue and is met in up to three ways:
     *  - $commissionReceived reached the platform with the payment (a split or
     *    an application fee): Debit Gateway Clearing.
     *  - $creditUsed was netted against commission the platform owed the
     *    organizer from refunds: Debit Organizer Payable, settling that debt.
     *  - the rest was not collected and the organizer owes it, as with an
     *    offline sale: Debit Organizer Payable.
     * Nothing is ever payable to the organizer from a sale like this.
     */
    public function recordOwnGatewayTicketSale(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        int $commissionReceived = 0,
        int $creditUsed = 0,
        ?string $providerReference = null
    ): ?LedgerTransaction {
        return $this->recordOwnGatewaySale(
            $event->tenant ?? Tenant::find($event->tenant_id),
            $event,
            "Ticket Sale (own gateway): {$event->name} - {$registration->full_name}",
            $reference,
            $grossAmount,
            $platformFee,
            $gatewayFee,
            $provider,
            $commissionReceived,
            $creditUsed,
            ['registration_id' => $registration->id],
            $providerReference,
            LedgerTransaction::TYPE_TICKET_SALE,
            LedgerCategoryEnum::Event,
            'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
            $event->currency ?: 'GHS',
        );
    }

    /**
     * The same as a ticket sale, for a voluntary contribution paid on the
     * organizer's own gateway.
     */
    public function recordOwnGatewayContribution(
        Event $event,
        EventContribution $contribution,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        int $commissionReceived = 0,
        int $creditUsed = 0,
        ?string $providerReference = null
    ): ?LedgerTransaction {
        return $this->recordOwnGatewaySale(
            $event->tenant ?? Tenant::find($event->tenant_id),
            $event,
            "Contribution (own gateway): {$event->name} - {$contribution->displayName()}",
            $reference,
            $grossAmount,
            $platformFee,
            $gatewayFee,
            $provider,
            $commissionReceived,
            $creditUsed,
            ['contribution_id' => $contribution->id],
            $providerReference,
            LedgerTransaction::TYPE_CONTRIBUTION,
            LedgerCategoryEnum::Event,
            'Platform commission fee ('.$event->effectivePlatformFeePercentage().'%)',
            $event->currency ?: 'GHS',
        );
    }

    /**
     * Record dues paid on the organisation's own gateway. The organisation
     * holds the money, so only the platform's commission is booked, met the
     * same three ways as an own-gateway ticket sale (received with the
     * payment, netted against credit, or owed as a receivable). Posted in
     * the dues category with no event; the charge row carries the figures.
     */
    public function recordOwnGatewayDuesPayment(
        Tenant $tenant,
        DuesCharge $charge,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        int $commissionReceived = 0,
        int $creditUsed = 0
    ): ?LedgerTransaction {
        return $this->recordOwnGatewaySale(
            $tenant,
            null,
            "Dues (own gateway): {$charge->member->name} ({$charge->period_start->toDateString()})",
            $reference,
            $grossAmount,
            $platformFee,
            $gatewayFee,
            $provider,
            $commissionReceived,
            $creditUsed,
            [],
            null,
            LedgerTransaction::TYPE_DUES_PAYMENT,
            LedgerCategoryEnum::Dues,
            'Dues commission ('.config('services.dues.platform_fee_percentage').'%)',
            $charge->currency ?: 'GHS',
        );
    }

    /**
     * Reverse the commission on a refunded own-gateway sale. Stripe returns
     * the application fee with the refund, so cash leaves the platform's
     * balance. A Paystack refund is paid from the organizer's balance and
     * does not claw back the platform's share, so the platform now owes the
     * organizer its commission back: Credit Organizer Payable, and the same
     * amount becomes credit that their later sales net against.
     * With $commissionReturned false (a policy for cancelled events) the
     * platform keeps the commission and nothing is reversed.
     */
    public function recordOwnGatewayRefund(
        Event $event,
        EventRegistration $registration,
        int $grossAmount,
        int $platformFee,
        string $reference,
        int $gatewayFee = 0,
        string $provider = 'paystack',
        ?string $providerReference = null,
        bool $commissionReturned = true
    ): ?LedgerTransaction {
        if ($grossAmount <= 0) {
            return null;
        }

        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $transaction = null;

        if ($platformFee > 0 && $commissionReturned) {
            $returnedWithRefund = $provider === 'stripe';

            $transaction = $this->postTransaction(
                $tenant,
                $event,
                LedgerTransaction::TYPE_REFUND,
                "Refund (own gateway): {$event->name} - {$registration->full_name}",
                $reference,
                [
                    [
                        'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                        'direction' => LedgerEntry::DIRECTION_DEBIT,
                        'amount' => $platformFee,
                        'description' => 'Platform fee reversal on refund',
                    ],
                    [
                        'code' => $returnedWithRefund ? LedgerAccount::CODE_GATEWAY_CLEARING : LedgerAccount::CODE_ORGANIZER_PAYABLE,
                        'direction' => LedgerEntry::DIRECTION_CREDIT,
                        'amount' => $platformFee,
                        'description' => $returnedWithRefund
                            ? 'Application fee returned with the refund'
                            : 'Commission owed back to the organizer: their gateway refunded the full amount',
                    ],
                ],
                null,
                $event->currency ?: 'GHS'
            );

            // A redelivered refund returns the transaction already posted,
            // and must not grant the credit twice.
            if (! $returnedWithRefund && $transaction->wasRecentlyCreated) {
                app(CommissionCredit::class)->grant($tenant, $event->currency ?: 'GHS', $platformFee);
            }
        }

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_REFUND,
            'provider_reference' => $providerReference ?? $reference,
        ], [
            'event_id' => $event->id,
            'registration_id' => $registration->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $commissionReturned ? $platformFee : 0,
            'net_amount' => -($grossAmount - $gatewayFee - ($commissionReturned ? $platformFee : 0)),
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * Record holdback reserve retention:
     * Debit:  Organizer Payable (reduces available balance)
     * Credit: Holdback Reserve (held in escrow)
     */
    public function recordHoldbackRetention(
        Event $event,
        int $amount,
        string $reference,
        ?string $description = null
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Holdback reserve retained from available balance',
            ],
            [
                'code' => LedgerAccount::CODE_HOLDBACK_RESERVE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'description' => 'Holdback escrow reserve held for dispute/refund buffer',
            ],
        ];

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_HOLDBACK_RETENTION,
            $description ?: "Holdback Retention: {$event->name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record holdback reserve release:
     * Debit:  Holdback Reserve
     * Credit: Organizer Payable (restores into available balance)
     */
    public function recordHoldbackRelease(
        Event $event,
        int $amount,
        string $reference,
        ?string $description = null
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = [
            [
                'code' => LedgerAccount::CODE_HOLDBACK_RESERVE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Holdback escrow reserve released',
            ],
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $amount,
                'description' => 'Holdback released into available organizer balance',
            ],
        ];

        return $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_HOLDBACK_RELEASE,
            $description ?: "Holdback Release: {$event->name}",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );
    }

    /**
     * Record a payout disbursement:
     * Debit:  Organizer Payable (settles liability)
     * Credit: Bank / Clearing (cash disbursed to organizer bank account)
     */
    public function recordPayout(
        Event $event,
        int $amount,
        string $reference,
        ?EventPayout $payout = null,
        int $transferFee = 0,
        bool $platformAbsorbsTransferFee = false,
        string $provider = 'paystack'
    ): LedgerTransaction {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);

        $entries = $this->payoutEntries($amount, $transferFee, $platformAbsorbsTransferFee);

        $transaction = $this->postTransaction(
            $tenant,
            $event,
            LedgerTransaction::TYPE_PAYOUT,
            "Payout Disbursement: {$event->name} (#{$reference})",
            $reference,
            $entries,
            null,
            $event->currency ?: 'GHS'
        );

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_PAYOUT,
            'provider_reference' => $payout?->provider_reference ?? $reference,
        ], [
            'event_id' => $event->id,
            'payout_id' => $payout?->id,
            'gross_amount' => $amount,
            /*
             * Zero, not the transfer fee: these columns describe collection,
             * and the settlement statement renders them as such. What the
             * payout cost to send lives on event_payouts.transfer_fee_amount.
             */
            'gateway_fee_amount' => 0,
            'commission_amount' => 0,
            'net_amount' => $amount,
            'currency' => $event->currency ?: 'GHS',
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * Record a payout of collected dues: the same entries as an event payout,
     * posted in the dues category with no event. The payout row carries the
     * figures, so no EventLedgerEntry is written.
     *
     * @param  EventPayout  $payout  The dues payout being settled; taken to keep the signature parallel with recordPayout, it is not written to.
     */
    public function recordDuesPayout(
        Tenant $tenant,
        int $amount,
        string $reference,
        EventPayout $payout,
        string $currency,
        int $transferFee = 0,
        bool $platformAbsorbsTransferFee = false
    ): LedgerTransaction {
        return $this->postTransaction(
            $tenant,
            null,
            LedgerTransaction::TYPE_PAYOUT,
            "Dues payout (#{$reference})",
            $reference,
            $this->payoutEntries($amount, $transferFee, $platformAbsorbsTransferFee),
            null,
            $currency,
            LedgerCategoryEnum::Dues
        );
    }

    /**
     * Returns the complete Trial Balance verifying ledger equilibrium.
     *
     * @return array<string, mixed>
     */
    public function getTrialBalance(Event $event): array
    {
        $tenant = $event->tenant ?? Tenant::find($event->tenant_id);
        $accounts = $this->ensureAccountsExist($tenant, $event, $event->currency ?: 'GHS');

        $rows = [];
        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($accounts as $code => $account) {
            $debits = (int) $account->entries()->where('direction', LedgerEntry::DIRECTION_DEBIT)->sum('amount');
            $credits = (int) $account->entries()->where('direction', LedgerEntry::DIRECTION_CREDIT)->sum('amount');
            $balance = $account->currentBalance();

            $totalDebits += $debits;
            $totalCredits += $credits;

            $rows[] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'debits' => $debits,
                'credits' => $credits,
                'balance' => $balance,
            ];
        }

        return [
            'is_balanced' => $totalDebits === $totalCredits,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'accounts' => $rows,
        ];
    }

    /**
     * The category a posting belongs to: an explicit one, or `event` when an
     * event is given. Money with no event must say what it is.
     */
    private function resolveCategory(?Event $event, ?LedgerCategoryEnum $category): LedgerCategoryEnum
    {
        $category ??= $event !== null ? LedgerCategoryEnum::Event : null;

        if ($category === null) {
            throw new InvalidArgumentException('A ledger category is required when there is no event.');
        }

        if (! $category->allowsEvent($event !== null)) {
            throw new InvalidArgumentException(sprintf('The %s category cannot be posted %s an event.', $category->value, $event !== null ? 'with' : 'without'));
        }

        return $category;
    }

    /**
     * The shared booking for a payment taken on the organisation's own
     * gateway. The settlement-statement EventLedgerEntry is written only when
     * there is an event; money with no event (dues) keeps its figures on its
     * own row.
     *
     * @param  array<string, string>  $subject  The registration_id or contribution_id the entry belongs to.
     * @param  string  $feeLabel  The description on the platform revenue credit.
     */
    private function recordOwnGatewaySale(
        Tenant $tenant,
        ?Event $event,
        string $description,
        string $reference,
        int $grossAmount,
        int $platformFee,
        int $gatewayFee,
        string $provider,
        int $commissionReceived,
        int $creditUsed,
        array $subject,
        ?string $providerReference,
        string $transactionType,
        LedgerCategoryEnum $category,
        string $feeLabel,
        string $currency,
        ?\App\Models\GivingFund $givingFund = null,
    ): ?LedgerTransaction {
        $received = min(max($commissionReceived, 0), $platformFee);
        $credited = min(max($creditUsed, 0), $platformFee - $received);
        $receivable = $platformFee - $received - $credited;

        // Commission we're owed but did not receive is not earnings in hand,
        // so it reports under its own type rather than alongside collected fees.
        if ($receivable > 0) {
            $transactionType = LedgerTransaction::TYPE_COMMISSION_RECEIVABLE;
        }

        if ($grossAmount <= 0) {
            return null;
        }

        if ($gatewayFee < 0 || $gatewayFee >= $grossAmount) {
            throw new InvalidArgumentException(
                "Gateway fee ({$gatewayFee}) must be non-negative and smaller than the gross amount ({$grossAmount})."
            );
        }

        $transaction = null;

        if ($platformFee > 0) {
            $entries = [];

            if ($received > 0) {
                $entries[] = [
                    'code' => LedgerAccount::CODE_GATEWAY_CLEARING,
                    'direction' => LedgerEntry::DIRECTION_DEBIT,
                    'amount' => $received,
                    'description' => 'Commission received with the payment',
                ];
            }

            if ($credited + $receivable > 0) {
                $entries[] = [
                    'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                    'direction' => LedgerEntry::DIRECTION_DEBIT,
                    'amount' => $credited + $receivable,
                    'description' => $receivable > 0
                        ? 'Commission owed by the organizer, not collected at payment'.($credited > 0 ? " (after {$credited} netted against credit)" : '')
                        : 'Commission netted against credit owed to the organizer',
                ];
            }

            $entries[] = [
                'code' => LedgerAccount::CODE_PLATFORM_REVENUE,
                'direction' => LedgerEntry::DIRECTION_CREDIT,
                'amount' => $platformFee,
                'description' => $feeLabel,
            ];

            $transaction = $this->postTransaction(
                $tenant,
                $event,
                $transactionType,
                $description,
                $reference,
                $entries,
                null,
                $currency,
                $category,
                $givingFund
            );
        }

        if ($event === null) {
            return $transaction;
        }

        EventLedgerEntry::firstOrCreate([
            'tenant_id' => $tenant->id,
            'type' => EventLedgerEntry::TYPE_CHARGE,
            'provider_reference' => $providerReference ?? $reference,
        ], $subject + [
            'event_id' => $event->id,
            'gross_amount' => $grossAmount,
            'gateway_fee_amount' => $gatewayFee,
            'commission_amount' => $platformFee,
            'net_amount' => $grossAmount - $gatewayFee - $platformFee,
            'currency' => $currency,
            'provider' => $provider,
        ]);

        return $transaction;
    }

    /**
     * The entries that settle a payout from Organizer Payable.
     *
     * When the organizer bears the transfer fee it is deducted from what
     * they are sent, so the payable clears at its full amount and the fee
     * is not the platform's expense. When the platform absorbs it instead,
     * it is a real cost and is booked as one.
     *
     * @return list<array{code: string, direction: string, amount: int, description: string}>
     */
    private function payoutEntries(int $amount, int $transferFee, bool $platformAbsorbsTransferFee): array
    {
        $entries = [
            [
                'code' => LedgerAccount::CODE_ORGANIZER_PAYABLE,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $amount,
                'description' => 'Payout settlement deducted from organizer payable',
            ],
        ];

        if ($platformAbsorbsTransferFee && $transferFee > 0) {
            $entries[] = [
                'code' => LedgerAccount::CODE_GATEWAY_FEES,
                'direction' => LedgerEntry::DIRECTION_DEBIT,
                'amount' => $transferFee,
                'description' => 'Transfer fee absorbed by the platform',
            ];
        }

        $entries[] = [
            'code' => LedgerAccount::CODE_BANK,
            'direction' => LedgerEntry::DIRECTION_CREDIT,
            'amount' => $platformAbsorbsTransferFee ? $amount + $transferFee : $amount,
            'description' => 'Cash transfer to organizer verified bank account',
        ];

        return $entries;
    }

    /**
     * The transaction already posted for this reference and type, if any.
     */
    private function findPostedTransaction(Tenant $tenant, string $reference, string $transactionType): ?LedgerTransaction
    {
        return LedgerTransaction::on('landlord')
            ->where('tenant_id', $tenant->id)
            ->where('reference', $reference)
            ->where('transaction_type', $transactionType)
            ->first();
    }
}
