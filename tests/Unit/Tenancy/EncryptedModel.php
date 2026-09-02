<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Casts\EncryptedJson;
use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Model;

final class EncryptedModel extends Model
{
    protected $table = 'encrypted_models';

    protected $guarded = [];

    protected $casts = [
        'secret_string' => EncryptedString::class,
        'secret_json' => EncryptedJson::class,
    ];
}
