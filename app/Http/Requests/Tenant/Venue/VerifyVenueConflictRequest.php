<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Venue;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finance asks the platform account to confirm a payment the older path could not honour.
 */
final class VerifyVenueConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('reconcile venue payments');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [

        ];
    }
}
