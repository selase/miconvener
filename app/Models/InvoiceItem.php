<?php

declare(strict_types=1);

namespace App\Models;

use App\Enum\UsageMetric;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class InvoiceItem extends Model
{
    use HasFactory;

    protected $connection = 'landlord';

    protected $fillable = [
        'invoice_id',
        'metric',
        'description',
        'quantity',
        'unit_price',
        'subtotal',
        'meta',
    ];

    protected $casts = [
        'metric' => UsageMetric::class,
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:6',
        'subtotal' => 'decimal:4',
        'meta' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
