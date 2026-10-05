<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\TenantAddon;

/**
 * Email credits: the plan's monthly email_credits, then email packs.
 */
final class EmailAllowance extends PrepaidCreditAllowance
{
    public const string FEATURE = 'email_credits';

    protected function feature(): string
    {
        return self::FEATURE;
    }

    protected function packType(): string
    {
        return TenantAddon::TYPE_EMAIL_PACK;
    }
}
