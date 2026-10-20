<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Staff granting or removing an organisation's Ghana price, with the reason.
 */
final class UpdatePricingRegionRequest extends FormRequest
{
    public const string GRANT = 'grant';

    public const string REMOVE = 'remove';

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-commercial-terms');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in([self::GRANT, self::REMOVE])],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'action.in' => 'Choose to grant or to remove the Ghana price.',
            'reason.required' => 'Say why, for the record.',
        ];
    }
}
