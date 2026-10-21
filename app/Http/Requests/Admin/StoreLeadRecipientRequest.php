<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\PlatformNotificationRecipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Another address to email about new Enterprise enquiries.
 */
final class StoreLeadRecipientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update setting');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('landlord.platform_notification_recipients', 'email')->where('topic', PlatformNotificationRecipient::ENTERPRISE_LEADS),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'That address already receives enquiries.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(mb_trim((string) $this->input('email')))]);
    }
}
