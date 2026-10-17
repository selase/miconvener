<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Giving;

use Illuminate\Foundation\Http\FormRequest;

final class SearchGivingMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage giving') && $this->user()->can('read finance') && $this->user()->can('manage members');
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:2', 'max:80']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['q.min' => 'Enter at least two characters of the member’s name.'];
    }
}
