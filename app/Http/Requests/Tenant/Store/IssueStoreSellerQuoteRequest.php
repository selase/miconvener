<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use App\Services\Marketplace\StoreFulfilmentOptions;
use App\Services\Marketplace\StoreSellerQuotes;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class IssueStoreSellerQuoteRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public static function quoteRules(): array
    {
        return StoreFulfilmentOptions::choiceRules() + [
            'buyer_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'valid_for_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'production_terms' => ['required', 'string', 'max:2000'],
            'customisation' => ['required', 'string', 'max:5000'],
            'printing_pesewas' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'delivery_pesewas' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*' => ['required', 'array:variant_id,quantity,unit_price_pesewas'],
            'lines.*.variant_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.unit_price_pesewas' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'tenant_id' => ['prohibited'], 'shop_id' => ['prohibited'], 'order_id' => ['prohibited'],
            'agreement_id' => ['prohibited'], 'percentage' => ['prohibited'], 'cap_pesewas' => ['prohibited'],
            'fee_bearer' => ['prohibited'], 'charged_pesewas' => ['prohibited'], 'settlement_mode' => ['prohibited'],
            'provider' => ['prohibited'], 'account_fingerprint' => ['prohibited'],
        ];
    }

    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return $tenant !== null && $this->user() !== null && app(StoreSellerQuotes::class)->canManage($tenant, $this->user(), true);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return self::quoteRules();
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['buyer_email.email' => 'Enter the email the buyer will verify to review and pay this quote.',
            'production_terms.required' => 'Describe the agreed production timeframe and when it starts.',
            'customisation.required' => 'Record the exact customisation, or state that none is required.',
            'lines.*.quantity.max' => 'A quote supports up to 10,000 items per product line.',
            'choice.destination.required_if' => 'Record the agreed delivery address before issuing the quote.'];
    }
}
