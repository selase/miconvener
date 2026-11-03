<?php

declare(strict_types=1);
namespace App\Http\Requests\Events;
use Illuminate\Foundation\Http\FormRequest;
final class ModerateAttendeeProfileRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() !== null; }
    /** @return array<string,list<string>> */
    public function rules(): array { return ['reason' => ['required', 'string', 'min:3', 'max:240']]; }
    public function messages(): array { return ['reason.required' => 'Record why this profile needs review.']; }
}
