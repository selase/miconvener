<?php

declare(strict_types=1);

use App\Models\CommunitySettings;
use App\Models\DuesReminder;
use App\Models\Tenant;
use App\Services\Community\CommunitySmsBudget;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsResult;
use App\Services\Tenancy\FeatureMeteringService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->tenant->features()->create(['feature_key' => 'sms_credits', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    CommunitySettings::for($this->tenant)->fill(['sms_monthly_limit' => 2])->save();
    $this->now = CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra');
    $this->travelTo($this->now);
    $this->budget = app(CommunitySmsBudget::class);
});

test('messages and legacy reminders share the same monthly cap', function () {
    DuesReminder::factory()->create(['tenant_id' => $this->tenant->id, 'sent_for_date' => '2026-10-15', 'status' => 'sent']);
    expect($this->budget->remaining($this->tenant, $this->now))->toBe(1);
    $first = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now);
    expect($first)->not->toBeNull()->and($this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now))->toBeNull();
    expect(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
});

test('reservation replay and finalization are idempotent', function () {
    $id = (string) Str::uuid();
    $row = $this->budget->reserve($this->tenant, 'community_message', $id, $this->now);
    expect($this->budget->reserve($this->tenant, 'community_message', $id, $this->now)->id)->toBe($row->id);
    $this->budget->finish($this->tenant, $row->id, SmsResult::accepted('provider-1'));
    $this->budget->finish($this->tenant, $row->id, SmsResult::refused('late contradiction'));
    expect($row->fresh()->status)->toBe('accepted')->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
});

test('unknown outcomes hold credits and monthly capacity until evidenced resolution', function () {
    $row = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now);
    $this->budget->finish($this->tenant, $row->id, SmsResult::unknown('Timeout'));
    expect($row->fresh()->status)->toBe('reserved')->and($row->fresh()->reason)->toBe('outcome_unknown')
        ->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9)->and($this->budget->remaining($this->tenant, $this->now))->toBe(1);
});

test('confirmed failures refund the exact receipt once and restore capacity', function () {
    $row = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now);
    $this->budget->finish($this->tenant, $row->id, SmsResult::refused('Rejected'));
    $this->budget->finish($this->tenant, $row->id, SmsResult::refused('Repeated'));
    expect($row->fresh()->status)->toBe('refused')->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(10)
        ->and($this->budget->remaining($this->tenant, $this->now))->toBe(2);
});

test('monthly accounting uses the organisation timezone and reservation month', function () {
    CommunitySettings::for($this->tenant)->fill(['timezone' => 'America/New_York'])->save();
    $utc = CarbonImmutable::parse('2026-11-01 00:30:00', 'UTC');
    $row = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $utc);
    expect($row->budget_month)->toBe('2026-10')->and($this->budget->remaining($this->tenant, $utc))->toBe(1)
        ->and($this->budget->remaining($this->tenant, $utc->addDay()))->toBe(2);
});

test('a new-style reminder reservation is not double-counted', function () {
    $reminder = DuesReminder::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'pending', 'sent_for_date' => '2026-10-15']);
    $this->budget->reserve($this->tenant, 'dues_reminder', $reminder->id, $this->now);
    $reminder->update(['status' => 'reserved']);
    expect($this->budget->remaining($this->tenant, $this->now))->toBe(1);
});

test('refunding after a plan reset cannot reduce new-month usage', function () {
    $row = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now);
    app(FeatureMeteringService::class)->resetUsage($this->tenant, 'sms_credits');
    app(SmsAllowance::class)->consume($this->tenant, 1);
    $this->budget->finish($this->tenant, $row->id, SmsResult::refused('Confirmed not sent'));
    expect(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
});

test('foreign reservations cannot be finalized', function () {
    $row = $this->budget->reserve($this->tenant, 'community_message', (string) Str::uuid(), $this->now);
    expect(fn () => $this->budget->finish(Tenant::factory()->create(), $row->id, SmsResult::refused('Denied')))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
