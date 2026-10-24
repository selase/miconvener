<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

final class ValidateStoreCollectionRequest extends StoreCollectionActionRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['credential' => ['required', 'array:manual_code,pass_id,purpose,secret'], 'credential.manual_code' => ['sometimes', 'string', 'max:32'], 'credential.pass_id' => ['sometimes', 'uuid'], 'credential.purpose' => ['sometimes', 'string', 'max:64'], 'credential.secret' => ['sometimes', 'string', 'max:128']];
    }
}
