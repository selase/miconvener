<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The verified requester accepts the exact terms they were shown. Only the
 * terms hash is accepted from the browser; amounts and dates come from the quote.
 */
final class AcceptVenueQuoteRequest extends FormRequest
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
        return [
            'operation_key' => ['required', 'uuid'],
            'accepted_quote_hash' => ['required', 'string', 'size:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accepted_quote_hash.required' => 'Review the quote before accepting it.',
            'accepted_quote_hash.size' => 'This quote changed. Reload the page and review it again.',
        ];
    }
}
