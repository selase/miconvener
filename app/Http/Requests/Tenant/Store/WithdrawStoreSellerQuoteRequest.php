<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Store;

use App\Services\Marketplace\StoreSellerQuotes;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class WithdrawStoreSellerQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();

        return $tenant !== null && $this->user() !== null && app(StoreSellerQuotes::class)->canManage($tenant, $this->user(), true);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['reason.required' => 'Record why this quote is being withdrawn.'];
    }
}
