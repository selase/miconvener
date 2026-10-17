<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GivingReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('read finance');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();

        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'currency' => ['nullable', Rule::in(config('services.giving.currencies'))], 'fund' => ['nullable', 'uuid', Rule::exists('giving_funds', 'id')->where('tenant_id', $tenant?->id)], 'format' => ['nullable', Rule::in(['csv'])]];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end date must be on or after the start date.', 'fund.exists' => 'Choose a fund in this organisation.'];
    }
}
