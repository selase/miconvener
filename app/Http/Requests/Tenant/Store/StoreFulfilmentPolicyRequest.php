<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use App\Services\Marketplace\StoreFulfilmentOptions;

final class StoreFulfilmentPolicyRequest extends CatalogRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return StoreFulfilmentOptions::policyRules();
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['operation_key.uuid' => 'Refresh this page before trying again.', 'choice.method.in' => 'Choose an option the seller offers.', 'policy.methods.min' => 'Offer at least one fulfilment method.'];
    }
}
