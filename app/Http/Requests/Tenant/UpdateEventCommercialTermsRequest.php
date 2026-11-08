<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateEventCommercialTermsRequest extends FormRequest
{
    public const array PROTECTED_MONEY_FIELDS = [
        'ticket_price', 'currency', 'member_ticket_price', 'fee_bearer', 'venue_booking_id',
        'allow_offline_payments', 'offline_payment_instructions', 'offline_payment_bank_name',
        'offline_payment_account_name', 'offline_payment_account_number',
        'offline_payment_momo_number', 'offline_payment_momo_network',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ticket_price' => ['required', 'integer', 'min:0'], 'currency' => ['required', 'string', 'size:3'], 'member_ticket_price' => ['nullable', 'integer', 'min:0', 'lte:ticket_price']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $event = Event::query()->where('tenant_id', app(TenantContext::class)->getTenant()?->id)->findOrFail($this->route('event'));
            $memberPrice = array_key_exists('member_ticket_price', $this->all()) ? $this->input('member_ticket_price') : $event->member_ticket_price;
            if ($memberPrice !== null && (int) $memberPrice > (int) $this->input('ticket_price')) {
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

    protected function prepareForValidation(): void
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant || ! $this->user() || ! \App\Services\Events\EventStaffScope::isEventOrganiser($this->user(), (string) $tenant->id)) {
            return;
        }
        $event = Event::query()->where('tenant_id', $tenant->id)->findOrFail($this->route('event'));
        foreach (self::PROTECTED_MONEY_FIELDS as $field) {
            $stored = $event->getAttribute($field);
            if ($this->exists($field) && ! (($this->input($field) === null || $this->input($field) === '') && $stored === null) && (string) $this->input($field) !== (string) $stored) {
                throw \Illuminate\Validation\ValidationException::withMessages([$field => 'Only an organisation administrator can change payment settings.']);
            }
        }
        $this->merge(['ticket_price' => $event->ticket_price, 'currency' => $event->currency]);
    }
}
