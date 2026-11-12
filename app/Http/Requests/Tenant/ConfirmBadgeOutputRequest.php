<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmBadgeOutputRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['confirm_paid_use' => ['required', 'accepted'], 'output_key' => ['required', 'uuid']];
    }
}
