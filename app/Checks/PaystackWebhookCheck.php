<?php

declare(strict_types=1);

namespace App\Checks;

use App\Services\Operations\OperationalSignals;
use Carbon\CarbonImmutable;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * A Paystack webhook we fail to process is a payment, renewal or payout we did
 * not record. A few refused signatures can be a stray probe; a run of them
 * usually means a secret key was rotated on one side only.
 */
final class PaystackWebhookCheck extends Check
{
    public const int WINDOW_MINUTES = 30;

    public const int SIGNATURE_FAILURE_THRESHOLD = 3;

    public function run(): Result
    {
        $signals = app(OperationalSignals::class);
        $since = CarbonImmutable::now()->subMinutes(self::WINDOW_MINUTES);
        $processing = $signals->webhookFailuresSince($since, 'processing');
        $signatures = $signals->webhookFailuresSince($since, 'signature');

        if ($processing !== []) {
            $latest = end($processing);

            return Result::make()
                ->failed(count($processing).' Paystack webhook(s) failed to process in the last '.self::WINDOW_MINUTES." minutes. Latest: {$latest['detail']}")
                ->shortSummary(count($processing).' failed');
        }

        if (count($signatures) >= self::SIGNATURE_FAILURE_THRESHOLD) {
            return Result::make()
                ->failed(count($signatures).' Paystack webhooks were refused for a bad signature in the last '.self::WINDOW_MINUTES.' minutes. Check that the Paystack secret keys match the dashboard.')
                ->shortSummary(count($signatures).' refused');
        }

        return Result::make()->ok()->shortSummary('Ok');
    }
}
