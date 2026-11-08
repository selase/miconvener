<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\EventAttendeeImport;
use App\Models\EventAttendeeImportRow;
use App\Models\EventRegistration;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AttendeeImport
{
    public const array HEADERS = ['full_name', 'email', 'phone', 'ticket_type', 'external_reference', 'payment_status', 'external_amount', 'currency'];

    /**
     * @param  array<string,string|null>  $mapping
     * @param  array<string,string|null>  $ticketMapping
     */
    public function preview(Event $event, User $actor, UploadedFile $file, array $mapping = [], array $ticketMapping = [], string $source = 'csv'): EventAttendeeImport
    {
        $raw = file_get_contents($file->getRealPath());
        if (! is_string($raw) || mb_strlen($raw, '8bit') > 5 * 1024 * 1024 || ! mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw)) {
            $this->invalid('Use a UTF-8 CSV text file no larger than 5 MiB.');
        }
        $records = $this->parse(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
        $header = array_shift($records);
        if ($header === null) {
            $this->invalid('Use the template headers: full_name,email.');
        }
        $headers = array_map('mb_trim', $header['cells']);
        $lower = array_map('mb_strtolower', $headers);
        if (count(array_unique($lower)) !== count($lower) || in_array('', $headers, true)) {
            $this->invalid('CSV headers must be unique and non-empty.');
        }
        foreach (self::HEADERS as $field) {
            if (! array_key_exists($field, $mapping)) {
                $index = array_search($field, $lower, true);
                $mapping[$field] = $index === false ? null : $headers[$index];
            }
        }
        $used = array_values(array_filter($mapping, fn (?string $value): bool => $value !== null && $value !== ''));
        if (count(array_unique($used)) !== count($used) || ! filled($mapping['full_name']) || ! filled($mapping['email']) || array_diff($used, $headers) !== []) {
            $this->invalid('Map full_name and email to distinct columns in this file.');
        }
        $event->load('ticketTypes');
        $rows = [];
        $seenEmails = [];
        $seenRefs = [];
        $seenFingerprints = [];
        foreach ($records as $record) {
            if (count(array_filter($record['cells'], fn (string $value): bool => mb_trim($value) !== '')) === 0) {
                continue;
            }
            if (count($record['cells']) !== count($headers)) {
                $this->invalid('Line '.$record['line'].' has a different number of columns.');
            }
            if (count($rows) >= 2000) {
                $this->invalid('Import at most 2,000 attendees.');
            }
            $cells = array_combine($headers, $record['cells']);
            $data = [];
            foreach ($mapping as $field => $column) {
                $data[$field] = filled($column) ? mb_trim($cells[$column]) : '';
            }
            $data['email'] = mb_strtolower($data['email']);
            $data['payment_status'] = $data['payment_status'] ?: 'free';
            $data['currency'] = mb_strtoupper($data['currency']);
            $errors = Validator::make($data, ['full_name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'], 'external_reference' => ['nullable', 'string', 'max:200'], 'payment_status' => ['required', 'in:free,paid_elsewhere,complimentary']])->errors()->all();
            if ($data['phone'] !== '') {
                $phone = PhoneNumber::toInternationalDigits($data['phone']);
                if ($phone === null) {
                    $errors[] = 'Use a valid international phone number.';
                }
                $data['phone'] = $phone === null ? null : '+'.$phone;
            } else {
                $data['phone'] = null;
            }
            if ($data['payment_status'] === 'paid_elsewhere' || $data['external_amount'] !== '') {
                abort_unless($actor->can('read finance'), 403, 'Finance permission is required for external payment declarations.');
            }
            $amount = $data['external_amount'];
            $data['external_amount'] = null;
            if ($amount !== '') {
                if ($data['payment_status'] !== 'paid_elsewhere' || ! preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount) || ! in_array($data['currency'], ['GHS', 'USD', 'EUR', 'GBP'], true)) {
                    $errors[] = 'External amounts require Paid elsewhere, a supported original currency and a non-negative decimal with at most two places.';
                } else {
                    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                    $data['external_amount'] = ((int) $whole * 100) + (int) mb_str_pad($fraction, 2, '0');
                }
            } elseif ($data['currency'] !== '' && ! in_array($data['currency'], ['GHS', 'USD', 'EUR', 'GBP'], true)) {
                $errors[] = 'Choose GHS, USD, EUR or GBP.';
            }
            $data['currency'] = $data['currency'] ?: null;
            $data['external_reference'] = $data['external_reference'] ?: null;
            $ticket = null;
            if ($event->ticketTypes->where('is_active', true)->isNotEmpty()) {
                $label = $data['ticket_type'];
                $id = $ticketMapping[$label] ?? null;
                $candidates = $event->ticketTypes->where('is_active', true)->filter(fn ($type): bool => $id ? $type->id === $id : mb_strtolower(mb_trim($type->name)) === mb_strtolower($label));
                if ($candidates->count() !== 1) {
                    $errors[] = 'Map this ticket label to one active ticket type belonging to this event.';
                } else {
                    $ticket = $candidates->first();
                    if ($ticket->isInviteOnly()) {
                        $errors[] = 'Access-code tickets are not supported by imports. Map to an unprotected ticket.';
                    }
                }
            } elseif ($data['ticket_type'] !== '') {
                $errors[] = 'This event has no ticket types; leave ticket_type empty.';
            }
            $data['ticket_type_id'] = $ticket?->id;
            $data['ticket_revision'] = $ticket ? hash('sha256', json_encode($ticket->only(['name', 'is_active', 'capacity', 'access_code', 'member_price']), JSON_THROW_ON_ERROR)) : null;
            if ($event->members_only) {
                $errors[] = 'Member-restricted admission is not supported by imports.';
            }
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $status = $errors === [] ? 'valid' : 'error';
            if (isset($seenFingerprints[$fingerprint])) {
                $status = 'duplicate';
                $errors[] = 'Exact duplicate of line '.$rows[$seenFingerprints[$fingerprint]]['line'].'.';
            } else {
                foreach ([$seenEmails[$data['email']] ?? null, $data['external_reference'] !== null ? ($seenRefs[$data['external_reference']] ?? null) : null] as $other) {
                    if ($other !== null) {
                        $status = 'conflict';
                        $errors[] = 'Shared email or contradictory source reference. Correct the CSV and preview again.';
                        $rows[$other]['status'] = 'conflict';
                        $rows[$other]['errors'][] = 'Another row shares this email or source reference.';
                    }
                }
                if ($status === 'valid') {
                    [$existingStatus, $existingMessage] = $this->existing($event, $data, $source);
                    if ($existingStatus !== null) {
                        $status = $existingStatus;
                        $errors[] = $existingMessage;
                    }
                }
                $seenFingerprints[$fingerprint] = count($rows);
                $seenEmails[$data['email']] = count($rows);
                if ($data['external_reference'] !== null) {
                    $seenRefs[$data['external_reference']] = count($rows);
                }
            }
            $rows[] = ['line' => $record['line'], 'data' => $data, 'errors' => $errors, 'status' => $status, 'fingerprint' => $fingerprint];
        }
        if ($rows === []) {
            $this->invalid('Add at least one attendee below the header.');
        }

        return $event->getConnection()->transaction(function () use ($event, $actor, $source, $raw, $rows): EventAttendeeImport {
            $import = EventAttendeeImport::create(['tenant_id' => $event->tenant_id, 'event_id' => $event->id, 'user_id' => $actor->id, 'source_namespace' => $source, 'file_digest' => hash('sha256', $raw), 'expires_at' => now()->addDay()]);
            foreach ($rows as $row) {
                $import->rows()->create(['tenant_id' => $event->tenant_id, ...$row]);
            }

            return $import->refresh();
        });
    }

    /** @param list<string> $rowIds */
    public function confirm(EventAttendeeImport $candidate, string $operationKey, array $rowIds): EventAttendeeImport
    {
        sort($rowIds);
        $digest = hash('sha256', json_encode($rowIds, JSON_THROW_ON_ERROR));
        $import = $candidate->getConnection()->transaction(function () use ($candidate, $operationKey, $rowIds, $digest): EventAttendeeImport {
            $event = Event::withoutGlobalScopes()->where('tenant_id', $candidate->tenant_id)->lockForUpdate()->findOrFail($candidate->event_id);
            $import = EventAttendeeImport::withoutGlobalScopes()->lockForUpdate()->findOrFail($candidate->id);
            if ($import->confirmed_at !== null) {
                abort_unless($import->operation_key === $operationKey && hash_equals($import->selection_digest, $digest), 409, 'This import was already confirmed with different selections.');

                return $import;
            }
            abort_if($import->expires_at->isPast(), 410, 'This preview expired. Upload and preview the CSV again.');
            abort_unless(in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true) && ! $event->trashed() && ($event->ends_at ?? $event->starts_at)->isFuture(), 409, 'This event ended, was cancelled or is unavailable.');
            abort_if(EventAttendeeImport::withoutGlobalScopes()->where('tenant_id', $import->tenant_id)->where('event_id', $import->event_id)->where('operation_key', $operationKey)->where('id', '!=', $import->id)->exists(), 409, 'This operation key belongs to another preview.');
            $selected = $import->rows()->whereIn('id', $rowIds)->get();
            abort_unless($selected->count() === count($rowIds) && $selected->every(fn ($row): bool => $row->status === 'valid'), 422, 'Select only valid rows from this preview.');
            $import->rows()->whereIn('id', $rowIds)->update(['selected' => true, 'status' => 'pending']);
            $import->update(['operation_key' => $operationKey, 'selection_digest' => $digest, 'confirmed_at' => now(), 'status' => 'queued']);

            return $import;
        });
        $this->dispatch($import);

        return $import->fresh();
    }

    public function dispatch(EventAttendeeImport $import): void
    {
        if (! in_array($import->status, ['queued', 'running', 'stopped'], true)) {
            return;
        }
        try {
            \App\Jobs\Events\ProcessAttendeeImport::dispatch($import->id)->afterCommit();
        } catch (Throwable) {
            $import->update(['status' => 'stopped', 'message' => 'Queue dispatch failed. Resume this batch to retry untouched rows.']);
        }
    }

    /** Each invocation commits at most 25 independent rows, then queues a continuation. */
    public function process(string $importId): void
    {
        $import = EventAttendeeImport::withoutGlobalScopes()->find($importId);
        if ($import === null || $import->confirmed_at === null || $import->status === 'completed') {
            return;
        }
        $context = app(\App\Services\Tenancy\TenantContext::class);
        $oldTenant = $context->getTenant();
        $oldId = $context->activeTenantId();
        $oldTeam = getPermissionsTeamId();
        try {
            $tenant = \App\Models\Tenant::find($import->tenant_id);
            $actor = User::find($import->user_id);
            $event = Event::withoutGlobalScopes()->where('tenant_id', $import->tenant_id)->find($import->event_id);
            if ($tenant === null || $actor === null || $event === null) {
                $import->update(['status' => 'stopped', 'message' => 'The event or initiating staff account is no longer available.']);

                return;
            }
            $context->setTenant($tenant);
            setPermissionsTeamId($tenant->id);
            $actor->unsetRelation('roles')->unsetRelation('permissions');
            if ($tenant->status !== \App\Enum\TenantStatusEnum::ACTIVE || $actor->status === User::STATUS_INACTIVE || ! $tenant->users()->whereKey($actor->id)->exists() || ! $actor->can('update event') || ! EventStaffScope::canAccessEvent($actor, $event)) {
                $import->update(['status' => 'stopped', 'message' => 'The initiating staff member no longer has event import permission. Restore access and resume.']);

                return;
            }
            $import->update(['status' => 'running', 'message' => null]);
            $rows = $import->rows()->where('selected', true)->where('status', 'pending')->limit(25)->get();
            foreach ($rows as $row) {
                try {
                    $event->getConnection()->transaction(function () use ($event, $import, $row, $actor): void {
                        $locked = Event::withoutGlobalScopes()->where('tenant_id', $import->tenant_id)->lockForUpdate()->findOrFail($event->id);
                        $locked->setRelation('tenant', $event->tenant);
                        $current = EventAttendeeImportRow::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);
                        if ($current->status !== 'pending') {
                            return;
                        }
                        $data = $current->data;
                        abort_if($data === null, 409, 'Row data has expired. Create a fresh preview.');
                        if ($data['payment_status'] === 'paid_elsewhere') {
                            abort_unless($actor->can('read finance'), 403, 'Finance permission is required for external declarations.');
                        }
                        abort_unless(in_array($locked->status, [Event::STATUS_DRAFT, Event::STATUS_PUBLISHED], true) && ! $locked->trashed() && ($locked->ends_at ?? $locked->starts_at)->isFuture(), 409, 'This event ended, was cancelled or is unavailable.');
                        abort_if($locked->members_only, 409, 'Member-restricted events cannot be imported.');
                        [$existingStatus, $message] = $this->existing($locked, $data, $import->source_namespace);
                        if ($existingStatus !== null) {
                            $current->update(['status' => $existingStatus === 'existing' ? 'skipped' : 'failed', 'errors' => [$message]]);

                            return;
                        }
                        $types = $locked->ticketTypes()->where('is_active', true)->get();
                        $ticket = $data['ticket_type_id'] !== null ? $types->firstWhere('id', $data['ticket_type_id']) : null;
                        if ($types->isNotEmpty() || $data['ticket_type_id'] !== null) {
                            abort_unless($ticket !== null && ! $ticket->isInviteOnly() && hash_equals($data['ticket_revision'], hash('sha256', json_encode($ticket->only(['name', 'is_active', 'capacity', 'access_code', 'member_price']), JSON_THROW_ON_ERROR))), 409, 'Ticket settings changed. Create a fresh preview.');
                        }
                        $count = $locked->registrations()->confirmed();
                        $capacity = $ticket === null ? $locked->capacity : $ticket->capacity;
                        if ($ticket !== null) {
                            $count->where('ticket_type_id', $ticket->id);
                        }
                        abort_if($capacity !== null && $count->count() >= $capacity, 409, 'No admission places remain. Increase capacity and use a fresh preview.');
                        $quota = app(RegistrationQuota::class);
                        $quota->acquire($locked);
                        $quota->assertRoom($locked);
                        $registration = new EventRegistration([
                            'tenant_id' => $locked->tenant_id, 'event_id' => $locked->id, 'attendee_import_id' => $import->id,
                            'registration_source' => 'external_import', 'external_source' => $import->source_namespace, 'external_reference' => $data['external_reference'],
                            'admission_payment_source' => $data['payment_status'], 'external_amount' => $data['external_amount'], 'external_currency' => $data['currency'],
                            'full_name' => $data['full_name'], 'email' => $data['email'], 'phone' => $data['phone'], 'ticket_type_id' => $ticket?->id,
                            'status' => EventRegistration::STATUS_CONFIRMED, 'amount' => 0, 'charged_amount' => 0, 'platform_fee_amount' => 0, 'discount_amount' => 0, 'gateway_fee_amount' => 0, 'currency' => $locked->currency,
                        ]);
                        $registration->id = (string) \Illuminate\Support\Str::uuid();
                        $registration->issueTicket();
                        $registration->save();
                        app(\App\Services\Tenancy\FeatureMeteringService::class)->recordUsage($locked->tenant, 'event_registrations');
                        $current->update(['registration_id' => $registration->id, 'status' => 'created', 'errors' => []]);
                    }, 3);
                } catch (Throwable $exception) {
                    if ($exception instanceof \Illuminate\Auth\Access\AuthorizationException || ($exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $exception->getStatusCode() === 403)) {
                        $import->update(['status' => 'stopped', 'message' => 'Finance permission was revoked. Restore permission and resume.']);

                        return;
                    }
                    $message = $exception instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $exception->getMessage() : ($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : 'This row could not be admitted. Correct it in a fresh preview; completed rows are retained.');
                    EventAttendeeImportRow::withoutGlobalScopes()->whereKey($row->id)->where('status', 'pending')->update(['status' => 'failed', 'errors' => json_encode([$message], JSON_THROW_ON_ERROR)]);
                }
            }
            if ($import->rows()->where('selected', true)->where('status', 'pending')->exists()) {
                $this->dispatch($import);
            } else {
                $import->update(['status' => 'completed', 'message' => 'Completed rows are retained. Correct failed rows in a fresh preview.']);
            }
        } finally {
            if ($oldTenant !== null) {
                $context->setTenant($oldTenant);
            } else {
                $context->clear();
            }
            $context->setActiveTenantId($oldId);
            setPermissionsTeamId($oldTeam);
        }
    }

    public function purge(): void
    {
        EventAttendeeImportRow::withoutGlobalScopes()->where('created_at', '<', now()->subDays(30))->whereNotNull('data')->update(['data' => null, 'errors' => null]);
        EventAttendeeImport::withoutGlobalScopes()->where('status', 'preview')->where('expires_at', '<', now())->update(['status' => 'expired', 'message' => 'Preview expired. Upload a fresh CSV.']);
    }

    public function recover(): void
    {
        EventAttendeeImport::withoutGlobalScopes()->whereNotNull('confirmed_at')->whereIn('status', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(5))->limit(100)->get()->each(fn ($import) => $this->dispatch($import));
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{?string,string}
     */
    public function existing(Event $event, array $data, string $source): array
    {
        $matches = EventRegistration::withoutGlobalScopes()->where('tenant_id', $event->tenant_id)->where('event_id', $event->id)->where(function ($query) use ($data, $source): void {
            $query->forEmail($data['email']);
            if ($data['external_reference'] !== null) {
                $query->orWhere(fn ($query) => $query->where('external_source', $source)->where('external_reference', $data['external_reference']));
            }
        })->get(['id', 'email', 'status', 'external_source', 'external_reference']);
        if ($matches->isEmpty()) {
            return [null, ''];
        }
        $match = $matches->first();
        if ($matches->count() !== 1 || mb_strtolower(mb_trim($match->email)) !== $data['email'] || in_array($match->status, [EventRegistration::STATUS_CANCELLED, EventRegistration::STATUS_REJECTED], true) || ($match->external_reference !== null && ($match->external_source !== $source || $match->external_reference !== $data['external_reference']))) {
            return ['conflict', 'Existing registration conflicts with this email/reference or is cancelled/rejected. Correct and preview again.'];
        }

        return ['existing', 'Already registered. Existing registrations are never modified.'];
    }

    /** @return list<array{line:int,cells:list<string>}> */
    private function parse(string $raw): array
    {
        $records = [];
        $cells = [];
        $cell = '';
        $state = 'start';
        $line = 1;
        $recordLine = 1;
        $length = mb_strlen($raw, '8bit');
        for ($i = 0; $i <= $length; $i++) {
            $char = $i === $length ? "\n" : $raw[$i];
            if ($state === 'quoted') {
                if ($i === $length) {
                    $this->invalid('Unterminated quoted field on line '.$recordLine.'.');
                }
                if ($char === '"') {
                    $state = 'closed';
                } else {
                    $cell .= $char;
                    if ($char === "\n") {
                        $line++;
                    }
                }
            } elseif ($state === 'closed' && $char === '"') {
                $cell .= '"';
                $state = 'quoted';
            } elseif ($char === ',' || $char === "\n" || $char === "\r") {
                $cells[] = $cell;
                $cell = '';
                $state = 'start';
                if (count($cells) > 20) {
                    $this->invalid('Use at most 20 columns.');
                }
                if ($char !== ',') {
                    $records[] = ['line' => $recordLine, 'cells' => $cells];
                    $cells = [];
                    if ($char === "\r" && ($raw[$i + 1] ?? '') === "\n") {
                        $i++;
                    }
                    $recordLine = ++$line;
                    if (count($records) > 2003) {
                        $this->invalid('Use at most 2,000 rows.');
                    }
                }
            } elseif ($state === 'start' && $char === '"') {
                $state = 'quoted';
            } elseif ($state === 'closed' || $char === '"') {
                $this->invalid('Malformed CSV quoting on line '.$recordLine.'.');
            } else {
                $state = 'text';
                $cell .= $char;
            }
            if (mb_strlen($cell, '8bit') > 4096) {
                $this->invalid('Keep every cell within 4,096 bytes.');
            }
        }

        return $records;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
