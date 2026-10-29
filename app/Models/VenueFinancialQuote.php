<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\VenueFinancialQuoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable priced version of the host's offer for an approved booking:
 * dates, itemised amounts, fee allocation, schedule and terms. Business
 * outcomes live in VenueFinancialDecision, never as edits to this row.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shop_id
 * @property string $booking_id
 * @property int $version
 * @property string $agreement_id
 * @property string $agreement_scope
 * @property string $hold_action_id
 * @property \Illuminate\Support\Carbon|null $hold_expires_at
 * @property string $currency
 * @property string $provider
 * @property string $account_fingerprint
 * @property int $rental_pesewas
 * @property int $extras_pesewas
 * @property int $security_pesewas
 * @property int $initial_pesewas
 * @property int $balance_pesewas
 * @property int $contract_fee_pesewas
 * @property int $initial_fee_pesewas
 * @property int $balance_fee_pesewas
 * @property \Illuminate\Support\Carbon|null $event_starts_at
 * @property \Illuminate\Support\Carbon|null $event_ends_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $balance_due_at
 * @property \Illuminate\Support\Carbon|null $security_due_at
 * @property string $cancellation_terms
 * @property string $support_contact
 * @property int $issued_by
 * @property string $issuer_name
 * @property string $operation_key
 * @property string $request_hash
 * @property string $terms_hash
 * @property array<string, mixed> $snapshot
 */
final class VenueFinancialQuote extends Model
{
    /** @use HasFactory<VenueFinancialQuoteFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $table = 'venue_financial_quotes';

    /** @var list<string> */
    protected $fillable = ['tenant_id', 'shop_id', 'booking_id', 'version', 'agreement_id', 'agreement_scope', 'hold_action_id', 'hold_expires_at', 'currency', 'provider', 'account_fingerprint', 'rental_pesewas', 'extras_pesewas', 'security_pesewas', 'initial_pesewas', 'balance_pesewas', 'contract_fee_pesewas', 'initial_fee_pesewas', 'balance_fee_pesewas', 'event_starts_at', 'event_ends_at', 'expires_at', 'balance_due_at', 'security_due_at', 'cancellation_terms', 'support_contact', 'issued_by', 'issuer_name', 'operation_key', 'request_hash', 'terms_hash', 'snapshot'];

    /** @return BelongsTo<VenueBooking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(VenueBooking::class);
    }

    /** @return BelongsTo<StoreCommercialAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(StoreCommercialAgreement::class);
    }

    /** @return BelongsTo<VenueBookingAction, $this> */
    public function holdAction(): BelongsTo
    {
        return $this->belongsTo(VenueBookingAction::class, 'hold_action_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'rental_pesewas' => 'integer', 'extras_pesewas' => 'integer', 'security_pesewas' => 'integer', 'initial_pesewas' => 'integer', 'balance_pesewas' => 'integer', 'contract_fee_pesewas' => 'integer', 'initial_fee_pesewas' => 'integer', 'balance_fee_pesewas' => 'integer', 'issued_by' => 'integer', 'hold_expires_at' => 'datetime', 'event_starts_at' => 'datetime', 'event_ends_at' => 'datetime', 'expires_at' => 'datetime', 'balance_due_at' => 'datetime', 'security_due_at' => 'datetime', 'snapshot' => 'array'];
    }
}
