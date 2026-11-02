<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\VenueFinancialQuote;
use App\Models\VenueQuoteNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VenueQuoteNotice> */
final class VenueQuoteNoticeFactory extends Factory
{
    protected $model = VenueQuoteNotice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'quote_id' => VenueFinancialQuote::factory(),
            'booking_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->booking_id,
            'shop_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->shop_id,
            'tenant_id' => fn (array $a) => VenueFinancialQuote::query()->findOrFail($a['quote_id'])->tenant_id,
            'kind' => VenueQuoteNotice::ISSUED, 'state' => VenueQuoteNotice::QUEUED, 'attempts' => 0,
        ];
    }
}
