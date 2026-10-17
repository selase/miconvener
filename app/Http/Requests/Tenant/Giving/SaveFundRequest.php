<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();
        abort_unless($tenant && app(\App\Services\Giving\GivingRollout::class)->enabled($tenant), 404);
        if (is_string($this->route('fund'))) {
            \App\Models\GivingFund::withoutGlobalScopes()->where('tenant_id', $tenant->id)->findOrFail($this->route('fund'));
        }

        return (bool) $this->user()?->can('manage giving');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();

        return [
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', Rule::unique('giving_funds')->where('tenant_id', $tenant?->id)->ignore($this->route('fund'))],
            'description' => ['nullable', 'string', 'max:10000'],
            'button_label' => ['sometimes', 'string', 'max:80'],
            'currency' => ['required', Rule::in(['GHS', 'USD', 'EUR', 'GBP'])],
            'kind' => ['required', Rule::in(['open', 'campaign'])],
            'target' => [Rule::requiredIf($this->input('kind') === 'campaign'), 'nullable', 'integer', 'min:1', 'max:999999999999'],
            'minimum_amount' => ['sometimes', 'integer', 'min:100', 'max:999999999999'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'close_on_target' => ['sometimes', 'boolean'],
            'state' => ['sometimes', Rule::in(['draft', 'open'])],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'progress' => ['sometimes', Rule::in(['hidden', 'percentage', 'amounts'])],
            'event_id' => ['nullable', 'uuid', Rule::exists('events', 'id')->where('tenant_id', $tenant?->id)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['slug.unique' => 'Choose a different fund address.', 'target.required' => 'Enter a target for this campaign.', 'currency.in' => 'Choose a supported collection currency.'];
    }
}
