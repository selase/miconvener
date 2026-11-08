<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Services\Events\EventStaffScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventHostsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant || ! $this->user()?->can('update event')) {
            return false;
        }
        $event = Event::query()->where('tenant_id', $tenant->id)->findOrFail($this->route('event'));

        return EventStaffScope::canAccessEvent($this->user(), $event);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'hosts' => ['present', 'array', 'list', 'max:10'],
            'hosts.*' => ['required', 'array:id,name,type,website_url,logo,remove_logo'],
            'hosts.*.id' => ['nullable', 'uuid', 'distinct'],
            'hosts.*.name' => ['required', 'string', 'max:160'],
            'hosts.*.type' => ['required', Rule::in(['individual', 'organisation'])],
            'hosts.*.website_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'hosts.*.logo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:3072', 'dimensions:max_width=4096,max_height=4096'],
            'hosts.*.remove_logo' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['hosts.max' => 'Add up to ten hosts.', 'hosts.*.name.required' => 'Enter a host name.', 'hosts.*.name.max' => 'Keep host names within 160 characters.', 'hosts.*.website_url.url' => 'Use a complete HTTPS website address.', 'hosts.*.logo.image' => 'Upload a JPEG, PNG or WebP image.', 'version.required' => 'Reload the event before saving hosts.'];
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('hosts') && ($this->input('hosts') === '' || $this->input('hosts') === null)) {
            $this->merge(['hosts' => []]);
        }
    }
}
