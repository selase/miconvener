<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EventMemberBenefitQuote;
use App\Models\EventRegistration;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EventMemberBenefitQuote> */
final class EventMemberBenefitQuoteFactory extends Factory
{
    protected $model = EventMemberBenefitQuote::class;

    public function definition(): array
    {
        return ['registration_id' => EventRegistration::factory(),
            'tenant_id' => fn (array $a) => EventRegistration::withoutGlobalScopes()->findOrFail($a['registration_id'])->tenant_id,
            'event_id' => fn (array $a) => EventRegistration::withoutGlobalScopes()->findOrFail($a['registration_id'])->event_id,
            'member_id' => fn (array $a) => Member::factory()->create(['tenant_id' => $a['tenant_id']])->id,
            'identity' => [], 'pricing' => [], 'amount' => 10000, 'charged_amount' => 10000, 'platform_fee_amount' => 200,
            'currency' => 'GHS', 'cart_fingerprint' => hash('sha256', fake()->uuid()), 'state' => 'quoted', 'expires_at' => now()->addMinutes(15)];
    }
}
