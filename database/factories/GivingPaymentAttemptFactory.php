<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\GivingPaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GivingPaymentAttempt> */
final class GivingPaymentAttemptFactory extends Factory
{
    protected $model = GivingPaymentAttempt::class;

    public function definition(): array
    {
        return [
            'fund_id' => GivingFund::factory(),
            'tenant_id' => fn (array $attributes) => GivingFund::withoutGlobalScopes()->findOrFail($attributes['fund_id'])->tenant_id,
            'gift_id' => fn (array $attributes) => GivingGift::factory()->create(['fund_id' => $attributes['fund_id'], 'tenant_id' => $attributes['tenant_id']])->id,
            'submission_key' => (string) \Illuminate\Support\Str::uuid(),
            'amount' => 10000, 'currency' => 'GHS', 'rate' => '1.00', 'cap_amount' => null,
            'bearer' => 'organizer', 'terms_source' => 'plan', 'commission_amount' => 100,
            'provider' => 'paystack', 'account_identity' => 'paystack:platform:'.hash_hmac('sha256', 'sk_test_giving', (string) config('app.key')),
            'settlement_mode' => 'platform_default', 'reference' => 'giving_'.\Illuminate\Support\Str::uuid(),
            'category' => 'giving', 'state' => 'open',
        ];
    }
}
