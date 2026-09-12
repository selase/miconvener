<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Contracts\SettlementGateway;
use App\Exceptions\PaymentFailedException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class PaystackSettlementGateway implements SettlementGateway
{
    private string $baseUrl = 'https://api.paystack.co';

    private readonly string $secret;

    public function __construct(?array $config = null)
    {
        $this->secret = $config['secret_key'] ?? config('services.settlement.paystack.secret_key') ?? '';
    }

    public function resolveAccount(string $bankCode, string $accountNumber): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/bank/resolve", [
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
            ])->throw();

            return ['account_name' => (string) $response->json('data.account_name')];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transferrecipient", [
                'type' => $type,
                'name' => $name,
                'account_number' => $accountNumber,
                'bank_code' => $bankCode,
                'currency' => $currency,
            ])->throw();

            return (string) $response->json('data.recipient_code');
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->post("{$this->baseUrl}/transfer", [
                'source' => 'balance',
                'reason' => 'Event settlement payout',
                'amount' => $amount,
                'currency' => $currency,
                'recipient' => $recipientCode,
                'reference' => $reference,
            ])->throw();

            return [
                'transfer_code' => (string) $response->json('data.transfer_code'),
                'status' => (string) $response->json('data.status'),
                'fee' => $response->json('data.fee_charged') ?? $response->json('data.fee'),
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function verifyTransfer(string $reference): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/transfer/verify/{$reference}")->throw();

            return [
                'status' => (string) $response->json('data.status'),
                'fee' => $response->json('data.fee_charged') ?? $response->json('data.fee'),
            ];
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }

    public function listBanks(string $country = 'ghana'): array
    {
        try {
            $response = Http::withToken($this->secret)->timeout(10)->get("{$this->baseUrl}/bank", [
                'country' => $country,
            ])->throw();

            return collect($response->json('data'))
                ->map(fn (array $bank): array => ['name' => (string) $bank['name'], 'code' => (string) $bank['code']])
                ->values()
                ->all();
        } catch (Throwable $e) {
            throw PaymentFailedException::fromProvider('paystack', $e->getMessage(), previous: $e);
        }
    }
}
