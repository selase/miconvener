<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\EventCertificateTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCertificateTemplateRequest extends FormRequest
{
    use ValidatesCertificateBackground;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'background_disk' => ['prohibited'],
            'background_path' => ['prohibited'],
            'signature_disk' => ['prohibited'],
            'signature_path' => ['prohibited'],
            'role' => ['required', Rule::in(EventCertificateTemplate::ROLES)],
            'design_mode' => ['required', Rule::in(['miconvener', 'custom_background'])],
            'orientation' => ['required', Rule::in(['landscape'])],
            'page_size' => ['required', Rule::in(['a4'])],
            'title' => ['required', 'string', 'max:255'],
            'body_template' => ['nullable', 'string', 'max:2000'],
            'issuer_name' => ['nullable', 'string', 'max:255'],
            'issuer_title' => ['nullable', 'string', 'max:255'],
            'show_qr' => ['required', 'boolean'],
            'show_cpd_hours' => ['required', 'boolean'],
            'default_cpd_hours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'layout' => ['nullable', 'array'],
            'background' => ['nullable', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=8000,max_height=8000'],
            'signature' => ['nullable', 'file', 'max:10240', 'mimetypes:image/png,image/jpeg', 'dimensions:max_width=8000,max_height=8000'],
            'remove_background' => ['sometimes', 'boolean'],
            'remove_signature' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $template = $this->route('template');
        $current = $template instanceof EventCertificateTemplate ? $template : null;
        $this->merge([
            'design_mode' => $this->input('design_mode', $current->design_mode ?? 'miconvener'),
            'orientation' => $this->input('orientation', $current->orientation ?? 'landscape'),
            'page_size' => $this->input('page_size', $current->page_size ?? 'a4'),
            'show_qr' => $this->input('show_qr', $current->show_qr ?? true),
            'show_cpd_hours' => $this->input('show_cpd_hours', $current->show_cpd_hours ?? false),
        ]);

        if (is_string($this->input('layout'))) {
            $this->merge(['layout' => json_decode((string) $this->input('layout'), true)]);
        }
    }
}
