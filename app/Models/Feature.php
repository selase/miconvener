<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use App\Traits\SpatieActivityLogs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Feature extends Model
{
    use HasFactory;
    use HasUuid;
    use SpatieActivityLogs;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'type', // boolean, limit, metered
        'description',
    ];

    public function packages()
    {
        return $this->belongsToMany(Package::class, 'package_features')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'feature_permissions');
    }
}
