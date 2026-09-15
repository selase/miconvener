<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per billing email ever queued. The unique (type, dedupe_key) index is
 * what guarantees a receipt or warning goes out once, however many times the
 * browser or Paystack reports the event behind it.
 */
final class BillingEmail extends Model
{
    use HasUuids;

    public const string TYPE_PAYMENT_RECEIPT = 'payment_receipt';

    public const string TYPE_PAYMENT_FAILED = 'payment_failed';

    public const string TYPE_SUBSCRIPTION_ENDING = 'subscription_ending';

    public const string TYPE_SUBSCRIPTION_ENDED = 'subscription_ended';

    protected $connection = 'landlord';

    protected $fillable = ['tenant_id', 'type', 'dedupe_key', 'recipients'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recipients' => 'array'];
    }
}
