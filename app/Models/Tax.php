<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Tax extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'name',
        'rate',
        'is_compound',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_compound' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Calculate tax for a given amount.
     */
    public static function calculateFor(float $amount): array
    {
        $activeTaxes = self::active()->get();
        $results = [];
        $runningTotal = $amount;
        $totalTax = 0;

        foreach ($activeTaxes as $tax) {
            $taxAmount = $tax->is_compound
                ? $runningTotal * ($tax->rate / 100)
                : $amount * ($tax->rate / 100);

            $results[] = [
                'id' => $tax->id,
                'name' => $tax->name,
                'rate' => $tax->rate,
                'amount' => $taxAmount,
            ];

            $runningTotal += $taxAmount;
            $totalTax += $taxAmount;
        }

        return [
            'taxes' => $results,
            'total_tax' => $totalTax,
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority');
    }
}
