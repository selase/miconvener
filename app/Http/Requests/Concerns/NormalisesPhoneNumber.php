<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;

/**
 * People type international numbers with a 00 prefix as often as with a plus.
 * Both mean the same, so the form is judged on the plus form.
 */
trait NormalisesPhoneNumber
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone_no'))) {
            $this->merge(['phone_no' => PhoneNumber::withInternationalPlus($this->input('phone_no'))]);
        }
    }
}
