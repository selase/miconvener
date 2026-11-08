<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use App\Services\Events\EventStaffScope;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

final class EventAttendeeImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = app(TenantContext::class)->getTenant();
        if (! $tenant || ! $this->user() || (! $this->user()->isGlobalSuperAdmin() && ! $tenant->users()->whereKey($this->user()->id)->exists()) || ! $this->user()->can('update event')) {
            return false;
        }
        $event = Event::query()->where('tenant_id', $tenant->id)->findOrFail($this->route('event'));

        return EventStaffScope::canAccessEvent($this->user(), $event);
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'preview' => ['file' => ['required', 'file', 'max:5120'], 'source_namespace' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.-]+$/'], 'mapping' => ['sometimes', 'array:full_name,email,phone,ticket_type,external_reference,payment_status,external_amount,currency'], 'mapping.*' => ['nullable', 'string', 'max:100'], 'ticket_mapping' => ['sometimes', 'array', 'max:2000'], 'ticket_mapping.*' => ['nullable', 'uuid']],
            'confirm' => ['operation_key' => ['required', 'uuid'], 'rows' => ['required', 'array', 'min:1', 'max:2000'], 'rows.*' => ['required', 'uuid', 'distinct']],
            'invite' => ['operation_key' => ['required', 'uuid'], 'registrations' => ['required', 'array', 'min:1', 'max:2000'], 'registrations.*' => ['required', 'uuid', 'distinct'], 'revisions' => ['required', 'array', 'max:2000'], 'revisions.*' => ['required', 'string', 'size:64']],
            default => [],
        };
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose a CSV file.', 'file.max' => 'Use a CSV no larger than 5 MiB.', 'operation_key.required' => 'Reload this import before submitting.', 'rows.required' => 'Select at least one valid row.'];
    }
}
