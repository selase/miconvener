<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventMemberBenefitAttempt;
use App\Models\EventMemberBenefitQuote;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EventMemberBenefitAttempt> */
final class EventMemberBenefitAttemptFactory extends Factory
{
    protected $model = EventMemberBenefitAttempt::class;

    public function definition(): array
    {
        return ['quote_id' => EventMemberBenefitQuote::factory(),
            'tenant_id' => fn (array $a) => EventMemberBenefitQuote::withoutGlobalScopes()->findOrFail($a['quote_id'])->tenant_id,
            'registration_id' => fn (array $a) => EventMemberBenefitQuote::withoutGlobalScopes()->findOrFail($a['quote_id'])->registration_id,
            'amount' => 10000, 'charged_amount' => 10000, 'platform_fee_amount' => 200, 'currency' => 'GHS',
            'provider' => 'paystack', 'account_key' => 'test:platform', 'settlement_mode' => 'platform_default', 'reference' => 'member_'.Str::uuid(), 'state' => 'preparing'];
    }

    public function forQuote(EventMemberBenefitQuote $quote): static
    {
        return $this->state(fn () => ['quote_id' => $quote->id, 'tenant_id' => $quote->tenant_id, 'registration_id' => $quote->registration_id,
            'amount' => $quote->amount, 'charged_amount' => $quote->charged_amount, 'platform_fee_amount' => $quote->platform_fee_amount, 'currency' => $quote->currency]);
    }
}
