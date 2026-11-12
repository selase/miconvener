<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class PurchaseBadgeTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['version_id' => ['required', 'uuid'], 'operation_key' => ['required', 'uuid'], 'accepted_terms_hash' => ['sometimes', 'string', 'size:64']];
    }
}
