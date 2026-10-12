<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\CommissionDestination;

/**
 * The accounts the platform's commission is paid into, and whether a given
 * Paystack subaccount settles to one of them. Until an administrator adds one,
 * the account set in the environment is used, so a deployment keeps working
 * while it moves over.
 */
final class CommissionDestinations
{
    /**
     * Whether any account is set up, which is what turns enforcement on.
     */
    public function isConfigured(): bool
    {
        return $this->accepted() !== [];
    }

    /**
     * The accounts organisers are told to point their subaccount at.
     *
     * @return list<array{label: string, currency: string, business_name: string, settlement_bank: ?string, account_number: string}>
     */
    public function current(): array
    {
        if (! CommissionDestination::query()->exists()) {
            return $this->fromEnvironment();
        }

        return CommissionDestination::query()->current()->orderBy('currency')->orderBy('label')->get()
            ->map(fn (CommissionDestination $destination): array => $this->toArray($destination))
            ->values()
            ->all();
    }

    /**
     * Whether a subaccount, as Paystack describes it, settles to an account
     * the platform accepts: the same account number and currency, and the same
     * bank when the account names one.
     *
     * @param  array<string, mixed>  $subaccount
     */
    public function accepts(array $subaccount): bool
    {
        $currency = mb_strtoupper((string) ($subaccount['currency'] ?? 'GHS'));
        $number = $this->digits((string) ($subaccount['account_number'] ?? ''));

        if ($number === '') {
            return false;
        }

        foreach ($this->accepted() as $destination) {
            if ($destination['currency'] !== $currency || $this->digits($destination['account_number']) !== $number) {
                continue;
            }

            if ($destination['settlement_bank'] === null
                || mb_strtolower($destination['settlement_bank']) === mb_strtolower((string) ($subaccount['settlement_bank'] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, currency: string, business_name: string, settlement_bank: ?string, account_number: string}>
     */
    private function accepted(): array
    {
        if (! CommissionDestination::query()->exists()) {
            return $this->fromEnvironment();
        }

        return CommissionDestination::query()->accepted()->get()
            ->map(fn (CommissionDestination $destination): array => $this->toArray($destination))
            ->values()
            ->all();
    }

    /**
     * @return list<array{label: string, currency: string, business_name: string, settlement_bank: ?string, account_number: string}>
     */
    private function fromEnvironment(): array
    {
        $number = config('services.commission_split.account_number');

        if (! filled($number)) {
            return [];
        }

        return [[
            'label' => 'Configured account',
            'currency' => 'GHS',
            'business_name' => (string) config('services.commission_split.business_name'),
            'settlement_bank' => filled(config('services.commission_split.settlement_bank')) ? (string) config('services.commission_split.settlement_bank') : null,
            'account_number' => (string) $number,
        ]];
    }

    /**
     * @return array{label: string, currency: string, business_name: string, settlement_bank: ?string, account_number: string}
     */
    private function toArray(CommissionDestination $destination): array
    {
        return [
            'label' => $destination->label,
            'currency' => mb_strtoupper($destination->currency),
            'business_name' => $destination->business_name,
            'settlement_bank' => $destination->settlement_bank,
            'account_number' => (string) $destination->account_number,
        ];
    }

    private function digits(string $value): string
    {
        return (string) preg_replace('/\D+/', '', $value);
    }
}
