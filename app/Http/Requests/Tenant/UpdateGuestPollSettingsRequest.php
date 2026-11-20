<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateGuestPollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['enabled.required' => 'Choose whether people may join live polls without registering.'];
    }
}
