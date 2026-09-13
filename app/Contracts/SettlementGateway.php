<?php

declare(strict_types=1);

namespace App\Contracts;

interface SettlementGateway
{
    /**
     * @return array{account_name: string}
     */
    public function resolveAccount(string $bankCode, string $accountNumber): array;

    public function createRecipient(string $type, string $name, string $accountNumber, string $bankCode, string $currency): string;

    /**
     * A status of 'otp' means the provider is holding the transfer until a
     * one-time code is supplied to finalizeTransfer(). No money has moved.
     *
     * @return array{transfer_code: string, status: string, fee: int|null}
     */
    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array;

    /**
     * Release a transfer the provider is holding for a one-time code.
     *
     * @return array{status: string, fee: int|null}
     */
    public function finalizeTransfer(string $transferCode, string $otp): array;

    /**
     * @return array{status: string, fee: int|null}
     */
    public function verifyTransfer(string $reference): array;

    /**
     * @return array<int, array{name: string, code: string}>
     */
    public function listBanks(string $country = 'ghana'): array;
}
