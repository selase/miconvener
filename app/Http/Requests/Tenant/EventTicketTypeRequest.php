<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\EventTicketType;
use App\Services\Events\MemberBenefitEligibility;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class EventTicketTypeRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return (bool) $this->user()?->can('update event');
    }

    final public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();
        $enabled = $tenant !== null && app(MemberBenefitEligibility::class)->enabled($tenant);

        return [
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string'],
            'badge_tier' => ['sometimes', Rule::in(EventTicketType::TIERS)], 'price' => ['required', 'integer', 'min:0'],
            'member_price' => [$enabled ? 'nullable' : 'prohibited', 'integer', 'min:0', 'lte:price'],
            'capacity' => ['nullable', 'integer', 'min:1'], 'is_active' => ['boolean'], 'sort_order' => ['integer'],
        ];
    }

    final public function messages(): array
    {
        return ['member_price.prohibited' => 'Member pricing is not enabled for this organisation.', 'member_price.integer' => 'Enter a whole number of minor currency units.', 'member_price.lte' => 'The member price cannot exceed the standard ticket price.', 'member_price.min' => 'The member price cannot be negative.'];
    }
}
