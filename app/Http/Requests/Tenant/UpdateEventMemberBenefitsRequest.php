<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Services\Events\MemberBenefitEligibility;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEventMemberBenefitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return $tenant !== null && (bool) $this->user()?->can('update event') && app(MemberBenefitEligibility::class)->enabled($tenant);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->getTenant();
        $event = Event::query()->where('tenant_id', $tenant?->id)->findOrFail($this->route('event'));

        return ['members_only' => ['required', 'boolean'], 'member_ticket_price' => ['nullable', 'integer', 'min:0', 'max:'.(int) $event->ticket_price]];
    }

    public function messages(): array
    {
        return ['members_only.required' => 'Choose whether this event is for members only.', 'member_ticket_price.integer' => 'Enter a whole number of minor currency units.', 'member_ticket_price.min' => 'The member price cannot be negative.', 'member_ticket_price.max' => 'The member price cannot exceed the standard ticket price.'];
    }
}
