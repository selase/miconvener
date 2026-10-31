<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\StoreCommercialAgreement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

final class StoreCommercialAgreementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('access-superadmin-dashboard');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'offering_scope' => ['sometimes', Rule::in([StoreCommercialAgreement::SCOPE_PURCHASE, StoreCommercialAgreement::SCOPE_RESERVATION])],
            'percentage' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'cap_pesewas' => ['nullable', 'integer', 'min:0', 'max:9007199254740991'],
            'fee_bearer' => ['required', Rule::in($this->input('offering_scope') === StoreCommercialAgreement::SCOPE_RESERVATION ? ['organizer'] : ['organizer', 'attendee'])],
            'gateway_estimate_rate' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'refund_gateway_fee_bearer' => ['required', Rule::in(['seller', 'platform'])],
            'collection_terms' => ['required', 'string', 'max:4000'],
            'refund_terms' => ['required', 'string', 'max:4000'],
            'support_contact' => ['required', 'string', 'max:500'],
            'receipt_terms' => ['required', 'string', 'max:4000'],
            'payout_policy' => ['required', 'array:holdback_pesewas,manual_release'],
            'payout_policy.holdback_pesewas' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'payout_policy.manual_release' => ['required', 'accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['fee_bearer.in' => 'Venue payments cannot add a buyer surcharge: the commission is taken from what the venue receives.',
            'percentage.required' => 'Approve an explicit percentage, including zero for a waiver.',
            'payout_policy.manual_release.accepted' => 'Commerce payouts require manual release in this pilot.',
            'refund_gateway_fee_bearer.required' => 'Choose who funds the original processing fee when refunding a buyer.'];
    }
}
