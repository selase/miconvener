<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SyncStaffScansRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The staff link token is the credential; the controller checks it.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scans' => ['present', 'array', 'max:500'],
            'scans.*.client_scan_id' => ['required', 'string', 'max:64'],
            'scans.*.registration_id' => ['required', 'uuid'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.qr_hash' => ['nullable', 'string', 'size:64'],
            'since' => ['nullable', 'date'],
            'sent_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scans.max' => 'Send at most 500 scans at a time.',
        ];
    }
}
