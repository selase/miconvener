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
     * @return array{transfer_code: string, status: string}
     */
    public function initiateTransfer(string $recipientCode, int $amount, string $currency, string $reference): array;

    /**
     * @return array{status: string}
     */
    public function verifyTransfer(string $reference): array;

    /**
     * @return array<int, array{name: string, code: string}>
     */
    public function listBanks(string $country = 'ghana'): array;
}
