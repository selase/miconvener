<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\TenantAddon;
use App\Services\Billing\PrepaidCreditAllowance;

/**
 * SMS credits: the plan's monthly sms_credits, then SMS packs. See
 * PrepaidCreditAllowance for how the two are spent.
 */
final class SmsAllowance extends PrepaidCreditAllowance
{
    public const string FEATURE = 'sms_credits';

    protected function feature(): string
    {
        return self::FEATURE;
    }

    protected function packType(): string
    {
        return TenantAddon::TYPE_SMS_PACK;
    }
}
