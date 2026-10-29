<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VenueFinancialDecision;
use App\Models\VenueFinancialQuote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VenueFinancialDecision> */
final class VenueFinancialDecisionFactory extends Factory
{
    protected $model = VenueFinancialDecision::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quote_id' => VenueFinancialQuote::factory(),
            'booking_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->booking_id,
            'shop_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->tenant_id,
            'decision' => VenueFinancialDecision::ACCEPTED, 'actor_type' => 'buyer', 'actor_id' => null, 'actor_name' => null,
            'buyer_email' => 'buyer@planner.test', 'reason' => null, 'terms_hash' => hash('sha256', 'quote-terms'),
            'operation_key' => fn () => (string) Str::uuid(), 'request_hash' => hash('sha256', 'decision-request'),
        ];
    }

    public function accepted(VenueFinancialQuote $quote): static
    {
        return $this->state(fn (): array => ['quote_id' => $quote->id]);
    }

    public function withdrawn(VenueFinancialQuote $quote): static
    {
        return $this->state(fn (): array => [
            'quote_id' => $quote->id, 'decision' => VenueFinancialDecision::WITHDRAWN, 'actor_type' => 'host', 'actor_name' => 'Test host',
            'buyer_email' => null, 'terms_hash' => null, 'reason' => 'Dates no longer available.',
        ]);
    }

    public function completed(VenueFinancialQuote $quote): static
    {
        return $this->state(fn (): array => [
            'quote_id' => $quote->id, 'decision' => VenueFinancialDecision::COMPLETED, 'actor_type' => 'staff', 'actor_name' => 'Test staff',
            'buyer_email' => null, 'terms_hash' => null, 'reason' => 'Event took place as booked.',
        ]);
    }
}
