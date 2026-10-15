<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class UploadMemberImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage members');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['file' => ['required', 'file', 'max:5120', 'extensions:csv,xlsx', 'mimes:csv,txt,xlsx']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV or XLSX spreadsheet.',
            'file.file' => 'Upload a valid spreadsheet.',
            'file.max' => 'The spreadsheet may not exceed 5 MB.',
            'file.extensions' => 'Choose a CSV or XLSX spreadsheet.',
            'file.mimes' => 'Choose a valid CSV or XLSX spreadsheet.',
        ];
    }
}
