<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateEventCommercialTermsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    public function rules(): array
    {
        return ['ticket_price' => ['required', 'integer', 'min:0'], 'currency' => ['required', 'string', 'size:3']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $event = Event::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->findOrFail($this->route('event'));
            if ($event->member_ticket_price !== null && $event->member_ticket_price > (int) $this->input('ticket_price')) {
                $validator->errors()->add('ticket_price', 'Update or clear the member price before lowering the standard price below it.');
            }
            if (mb_strtoupper($this->input('currency')) !== mb_strtoupper($event->currency) && $event->registrations()->where('member_benefit_required', true)->exists()) {
                $validator->errors()->add('currency', 'This event has member-benefit registrations. Keep their original currency; create another event for a different currency.');
            }
        }];
    }

    public function messages(): array
    {
        return ['currency.size' => 'Use a three-letter currency code.', 'ticket_price.min' => 'The standard ticket price cannot be negative.'];
    }
}
