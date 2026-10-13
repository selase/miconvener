<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An optional note sent with an action on a charge.
 */
abstract class ChargeNoteRequest extends FormRequest
{
    final public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage members');
    }

    /**
     * @return array<string, mixed>
     */
    final public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    final public function messages(): array
    {
        return [
            'note.max' => 'The note may not be longer than 255 characters.',
        ];
    }
}
