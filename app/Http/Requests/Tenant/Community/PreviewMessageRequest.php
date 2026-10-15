<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

final class PreviewMessageRequest extends StoreMessageRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['idempotency_key'], $rules['confirm_authority']);

        return $rules;
    }
}
