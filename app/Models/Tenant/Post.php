<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class Post extends Model
{
    use \App\Traits\BelongsToTenant, HasFactory, HasUuid;

    protected $connection = 'tenant';

    protected $fillable = ['title', 'content'];
}
