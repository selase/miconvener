<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A free message left on an event's wall. `website` is a hidden field no
 * person fills in, so a value there marks the post as a bot's.
 */
final class StoreGoodwillMessageRequest extends FormRequest
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
            'contributor_name' => ['required', 'string', 'max:255'],
            'tribute_message' => ['required', 'string', 'max:1000'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'website' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contributor_name.required' => 'Please tell us your name.',
            'tribute_message.required' => 'Please write your message.',
            'tribute_message.max' => 'Please keep your message under 1,000 characters.',
        ];
    }
}
