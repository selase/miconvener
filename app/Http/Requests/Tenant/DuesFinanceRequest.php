<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\CommunitySettings;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final class DuesFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('read finance') ?? false)
            && app(TenantContext::class)->getTenant()->showsFinance();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'from.date_format' => 'Enter a valid start date (YYYY-MM-DD).',
            'to.date_format' => 'Enter a valid end date (YYYY-MM-DD).',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ];
    }

    public function timezone(): string
    {
        return CommunitySettings::for(app(TenantContext::class)->getTenant())->timezone;
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('from'), $this->timezone())->startOfDay()->utc();
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('to'), $this->timezone())->endOfDay()->utc();
    }

    protected function prepareForValidation(): void
    {
        $today = CarbonImmutable::now($this->timezone());
        $this->merge([
            'from' => $this->input('from', $today->startOfMonth()->toDateString()),
            'to' => $this->input('to', $today->toDateString()),
        ]);
    }
}
