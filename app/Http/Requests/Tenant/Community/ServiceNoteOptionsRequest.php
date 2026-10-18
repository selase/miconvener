<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceNoteOptionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage community library');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['session' => ['nullable', 'uuid'], 'event' => ['nullable', 'uuid'], 'search' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'occurrence_page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }
}
