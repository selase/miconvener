<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\BillingEmail;
use App\Models\Tenant;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queues a billing email at most once per (type, dedupe key).
 *
 * The claim row is written before the email is queued, so two paths reporting
 * the same payment at the same moment cannot both send. If queueing fails the
 * claim is released, so a redelivered event can still send it.
 */
final class BillingEmailSender
{
    /**
     * @param  array<int, string|null>  $recipients
     */
    public function send(Tenant $tenant, string $type, string $dedupeKey, array $recipients, Mailable $mail): bool
    {
        $recipients = array_values(array_unique(array_filter(array_map(
            fn (?string $email): string => mb_strtolower(mb_trim((string) $email)),
            $recipients,
        ))));

        if ($recipients === []) {
            Log::warning("Billing email {$type} for tenant {$tenant->id} has no recipient", ['key' => $dedupeKey]);

            return false;
        }

        /*
         * ON CONFLICT DO NOTHING rather than catching a unique violation: on
         * Postgres a failed insert aborts any transaction the caller is in.
         */
        $claimId = (string) Str::uuid7();
        $claimed = BillingEmail::query()->insertOrIgnore([
            'id' => $claimId,
            'tenant_id' => $tenant->id,
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'recipients' => json_encode($recipients),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        try {
            Mail::to($recipients)->queue($mail);
        } catch (Throwable $e) {
            BillingEmail::query()->whereKey($claimId)->delete();

            throw $e;
        }

        return true;
    }
}
