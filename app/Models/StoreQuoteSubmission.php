<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $order_id */
final class StoreQuoteSubmission extends Model
{
    /** @use HasFactory<\Database\Factories\StoreQuoteSubmissionFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = ['order_id', 'tenant_id', 'shop_id', 'buyer_email', 'submission_key', 'cart_hash'];

    protected $hidden = ['buyer_email'];
}
