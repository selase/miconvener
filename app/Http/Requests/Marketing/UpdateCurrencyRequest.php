<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Services\Pricing\VisitorCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['currency' => ['required', Rule::in(VisitorCurrency::SUPPORTED)]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['currency.in' => 'Prices can be shown in GHS or USD.'];
    }
}
