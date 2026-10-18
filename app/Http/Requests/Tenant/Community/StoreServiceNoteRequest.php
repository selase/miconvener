<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Community;

use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Rule;

final class StoreServiceNoteRequest extends LibraryItemRequest
{
    /** @return array<string,mixed> */
    protected function contextRules(): array
    {
        $tenantId = app(TenantContext::class)->getTenant()?->id;

        return ['kind' => ['required', Rule::in(['service_note'])],
            'event_id' => ['required', 'uuid', Rule::exists('events', 'id')->where('tenant_id', $tenantId)->where('is_recurring', true)->whereNull('deleted_at')],
            'session_id' => ['required', 'uuid', Rule::exists('event_sessions', 'id')->where('tenant_id', $tenantId)->where('event_id', is_string($this->input('event_id')) ? $this->input('event_id') : null)->where('is_occurrence', true)]];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['kind' => 'service_note']);
    }
}
