<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant\Dues;

use App\Models\MembershipType;
use App\Services\Tenancy\TenantContext;
use Illuminate\Validation\Validator;

/**
 * Edits a dues class, including deactivating it. Classes are never deleted
 * because members and past charges refer to them. Once a class has charges
 * its currency and period are fixed: changing them would mix currencies in
 * one class's totals and redraw periods already billed.
 */
final class UpdateMembershipTypeRequest extends MembershipTypeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->classRules((string) $this->route('type')),
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $class = MembershipType::query()
                    ->where('tenant_id', app(TenantContext::class)->getTenant()?->id)
                    ->find((string) $this->route('type'));

                if (! $class instanceof MembershipType) {
                    return;
                }
                $start = $this->input('dues_start_on');
                $introducing = ! $class->hasDues() && $this->filled('amount');
                if ($introducing && ! $this->filled('dues_start_on')) {
                    $validator->errors()->add('dues_start_on', 'Choose the date from which members should start paying dues.');
                }
                if (is_string($start) && ! $validator->errors()->has('dues_start_on')) {
                    $tenant = app(TenantContext::class)->getTenant();
                    $today = \Carbon\CarbonImmutable::now(\App\Models\CommunitySettings::for($tenant)->timezone)->toDateString();
                    if ($introducing && $start < $today) {
                        $validator->errors()->add('dues_start_on', 'Dues cannot start before today. Earlier membership periods remain free of dues.');
                    }
                }
                if (! $introducing && $this->exists('dues_start_on') && $start !== $class->dues_start_on?->toDateString()) {
                    $validator->errors()->add('dues_start_on', 'The dues start date is fixed. Existing schedules and charges must be preserved.');
                }

                if (! $class->charges()->exists()) {
                    return;
                }

                if (is_string($this->input('currency')) && $this->input('currency') !== $class->currency) {
                    $validator->errors()->add('currency', 'This class already has charges, so its currency cannot change. Create a new class instead.');
                }

                if ($this->input('period') !== $class->period?->value) {
                    $validator->errors()->add('period', 'This class already has charges, so its billing period cannot change. Create a new class instead.');
                }
            },
        ];
    }
}
