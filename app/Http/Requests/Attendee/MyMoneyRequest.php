<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class MyMoneyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $required = $this->routeIs('attendee.my.communities.statement') ? 'required' : 'sometimes';

        return ['tenant_slug' => ['sometimes', 'string', 'max:100'], 'member_id' => ['sometimes', 'uuid'], 'currency' => ['sometimes', 'string', 'size:3'], 'page' => ['sometimes', 'integer', 'min:1'], 'contributions_page' => ['sometimes', 'integer', 'min:1'], 'from' => [$required, 'date_format:Y-m-d'], 'to' => [$required, 'date_format:Y-m-d', 'after_or_equal:from']];
    }

    public function messages(): array
    {
        return ['from.required' => 'Choose a start date for your statement.', 'to.required' => 'Choose an end date for your statement.', 'to.after_or_equal' => 'End date must be on or after start date.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->filled(['from', 'to']) && \Carbon\CarbonImmutable::parse($this->string('from')->toString())->diffInDays(\Carbon\CarbonImmutable::parse($this->string('to')->toString())) > 366) {
                $validator->errors()->add('to', 'Choose a statement period of at most 366 days.');
            }
        });
    }
}
