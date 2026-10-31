<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use Illuminate\Foundation\Http\FormRequest;

final class FollowingEventAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('send community messages') ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $rules = StoreMessageRequest::createFrom($this)->rules();
        unset($rules['audience'],$rules['audience.kind'],$rules['audience.status'],$rules['audience.group_ids'],$rules['audience.group_ids.*']);
        $rules['source_event_id'] = ['required', 'uuid'];
        $rules['audience'] = ['prohibited'];
        if ($this->routeIs('tenant.following.announcements.preview')) {
            unset($rules['idempotency_key'],$rules['confirm_authority']);
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['source_event_id.required' => 'Choose a published upcoming event.', 'audience.prohibited' => 'Event announcements are for consented followers only.'];
    }
}
