<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A published exchange rate as fetched (Bank of Ghana interbank mid rate, or
 * the backup republisher).
 *
 * @property int $id
 * @property string $base
 * @property string $quote
 * @property string $rate
 * @property \Illuminate\Support\Carbon $as_of
 * @property string $source
 * @property \Illuminate\Support\Carbon $fetched_at
 */
final class ExchangeRate extends Model
{
    /** @use HasFactory<\Database\Factories\ExchangeRateFactory> */
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = ['base', 'quote', 'rate', 'as_of', 'source', 'fetched_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'as_of' => 'date', 'fetched_at' => 'datetime'];
    }
}
