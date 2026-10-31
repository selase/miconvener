<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;

final class ServiceNotesListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['series_page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'series_search' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'event' => ['nullable', 'uuid'], 'from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])]];
    }
}
