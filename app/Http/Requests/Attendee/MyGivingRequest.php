<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class MyGivingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $required = $this->routeIs('attendee.my.giving.statement') ? 'required' : 'sometimes';

        return ['source_type' => ['sometimes', Rule::in(['gift', 'event_contribution'])], 'source' => ['sometimes', Rule::in(['online', 'recorded_cash', 'bulk_cash'])], 'tenant_slug' => ['sometimes', 'string', 'max:100'], 'member_id' => ['sometimes', 'uuid'], 'currency' => ['sometimes', Rule::in(config('services.giving.currencies'))], 'giving_page' => ['sometimes', 'integer', 'min:1'], 'from' => [$required, 'date_format:Y-m-d'], 'to' => [$required, 'date_format:Y-m-d', 'after_or_equal:from'], 'format' => ['sometimes', Rule::in(['csv'])]];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return ['from.required' => 'Choose a start date.', 'to.required' => 'Choose an end date.', 'to.after_or_equal' => 'The end date must be on or after the start date.'];
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
