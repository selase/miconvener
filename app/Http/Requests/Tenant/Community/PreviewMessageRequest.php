<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send community messages') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = StoreMessageRequest::createFrom($this)->rules();
        unset($rules['idempotency_key'], $rules['confirm_authority']);

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return StoreMessageRequest::createFrom($this)->messages();
    }
}
