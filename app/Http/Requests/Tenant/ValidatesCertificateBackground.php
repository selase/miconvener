<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Models\EventCertificateTemplate;
use App\Services\Design\ArtifactArtworkService;
use Closure;
use Illuminate\Validation\Validator;
use RuntimeException;

trait ValidatesCertificateBackground
{
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
                if ($this->routeIs('tenant.events.certificates.templates.draft-preview')) {
                    return;
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
}
