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

    /** @return array<string, mixed> */
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

    /** @return list<callable(\Illuminate\Validation\Validator): void> */
    final public function after(): array
    {
        return [function (\Illuminate\Validation\Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || array_key_exists('member_price', $this->all())) {
                return;
            }
            $tenant = app(TenantContext::class)->getTenant();
            $type = EventTicketType::query()->where('tenant_id', $tenant?->id)->where('event_id', $this->route('event'))->find($this->route('ticketType'));
            if ($type?->member_price !== null && $type->member_price > (int) $this->input('price')) {
                $validator->errors()->add('member_price', 'Update or clear the member price before lowering the standard price below it.');
            }
        }];
    }

    final public function messages(): array
    {
        return ['member_price.prohibited' => 'Member pricing is not enabled for this organisation.', 'member_price.integer' => 'Enter a whole number of minor currency units.', 'member_price.lte' => 'The member price cannot exceed the standard ticket price.', 'member_price.min' => 'The member price cannot be negative.'];
    }
}
