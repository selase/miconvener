<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveBadgeLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(['event', 'organisation', 'platform'])],
            'expected_version' => ['required', 'integer', 'min:1'],
            'design_id' => ['nullable', 'uuid'],
            'price_pesewas' => ['sometimes', 'integer', Rule::in([0, 5000])],
            'asset_rights_confirmed' => ['accepted_if:scope,platform'],
        ];
    }
}
