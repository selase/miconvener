<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class BadgeTemplateFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-template-refunds');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['action' => ['required', 'in:refund,refresh,restore,verify'], 'operation_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'], 'exception' => ['sometimes', 'boolean'],
            'capture_id' => ['nullable', 'uuid'], 'refund_id' => ['nullable', 'uuid'], 'attempt_id' => ['nullable', 'uuid']];
    }
}
