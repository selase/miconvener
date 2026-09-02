<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class PackageFeature extends Pivot
{
    // use HasFactory; // Pivot usually doesn't need factory unless strictly tested alone
    // use SpatieActivityLogs; // Can keep if pivot logging is needed

    protected $table = 'package_features';

    protected $fillable = [
        'package_id',
        'feature_id',
        'value',
    ];
}
