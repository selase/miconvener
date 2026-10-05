<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateAgreedPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('access-superadmin-dashboard');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'price' => ['nullable', 'numeric', 'min:1', 'max:10000000', 'decimal:0,2'],
            'interval' => ['required_with:price', 'nullable', 'in:month,year'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.min' => 'Enter the agreed price in GHS, at least GHS 1. Leave it empty to remove the price.',
            'price.decimal' => 'Use at most two decimal places, e.g. 2500 or 2500.50.',
            'interval.required_with' => 'Choose whether the price is per month or per year.',
        ];
    }
}
