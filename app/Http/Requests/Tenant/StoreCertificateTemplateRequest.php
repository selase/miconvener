<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Models\EventCertificateTemplate;
use App\Services\Design\ArtifactArtworkService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use RuntimeException;

final class StoreCertificateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
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

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('design_mode') !== 'custom_background' || $this->hasFile('background')) {
                return;
            }
            $template = $this->route('template');
            $event = $this->route('event');
            if (! $template instanceof EventCertificateTemplate && $event instanceof Event) {
                $template = $event->certificateTemplates()->where('role', $this->input('role'))->first();
            }
            if ($template instanceof EventCertificateTemplate && $event instanceof Event && $template->event_id !== $event->id) {
                return;
            }
            try {
                if ($this->boolean('remove_background') || ! $template instanceof EventCertificateTemplate || ! is_string($template->background_disk) || ! is_string($template->background_path)) {
                    throw new RuntimeException('Missing background.');
                }
                $artwork = app(ArtifactArtworkService::class);
                $artwork->dataUri($template->background_disk, $template->background_path);
                if (@getimagesizefromstring($artwork->contents($template->background_disk, $template->background_path)) === false) {
                    throw new RuntimeException('Invalid background.');
                }
            } catch (RuntimeException) {
                $validator->errors()->add('background', 'Upload background artwork or keep the current image for a custom certificate.');
            }
        }];
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
