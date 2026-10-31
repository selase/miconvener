<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\Tenant;

final class GivingIdentity
{
    public static function emailKey(Tenant $tenant, string $email): string
    {
        $key = hash_hmac('sha256', 'giving-email:v1:'.$tenant->id, (string) config('app.key'), true);

        return hash_hmac('sha256', mb_strtolower(mb_trim($email)), $key);
    }
}
