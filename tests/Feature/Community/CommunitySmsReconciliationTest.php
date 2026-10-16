<?php

declare(strict_types=1);

use App\Models\CommunityMessage;
use App\Models\CommunityMessageDelivery;
use App\Models\DuesReminder;
use App\Models\MessageOutcomeResolution;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\EmailAllowance;
use App\Services\Community\CommunitySmsBudget;
use App\Services\Community\CommunitySmsReconciler;
use App\Services\Sms\SmsAllowance;
use App\Services\Sms\SmsResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Africa/Accra'));
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    $this->tenant = Tenant::factory()->create();
    foreach (['sms_credits', 'email_credits'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 10]]);
    }
    $this->operator = User::factory()->create(['tenant_id' => null]);
    setPermissionsTeamId(null);
    $this->operator->assignRole(Role::query()->where('name', 'Superadmin')->whereNull('tenant_id')->firstOrFail());
    $this->reservation = app(CommunitySmsBudget::class)->reserve($this->tenant, 'community_message', (string) Str::uuid(), CarbonImmutable::now());
    app(CommunitySmsBudget::class)->finish($this->tenant, $this->reservation->id, SmsResult::unknown('Interrupted'));
    $this->input = ['tenant_id' => $this->tenant->id, 'source_kind' => 'sms_reservation', 'source_id' => $this->reservation->id, 'outcome' => 'confirmed_accepted', 'evidence' => 'Provider support ticket 123 confirms acceptance.', 'reason' => 'Reconciled with the original provider account.', 'confirm' => true];
});

test('only global application superadmins can resolve uncertain credits', function () {
    $admin = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $admin->assignRole('Org Superadmin');
    $admin->update(['email' => 'admin-lookalike@example.test']);
    expect(fn () => app(CommunitySmsReconciler::class)->resolve($admin, $this->tenant, $this->reservation->id, 'confirmed_not_submitted', 'Provider refused before submission.', 'Reconciled against provider log.'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    $this->actingAs($admin)->post(route('admin.messages.reconciliation.resolve'), $this->input)->assertForbidden();
    expect($this->reservation->fresh()->status)->toBe('reserved');
});

test('accepted outcomes preserve spend and audit who decided with evidence', function () {
    app(CommunitySmsReconciler::class)->resolve($this->operator, $this->tenant, $this->reservation->id, 'confirmed_accepted', $this->input['evidence'], $this->input['reason']);
    expect($this->reservation->fresh()->status)->toBe('accepted')->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
    $audit = MessageOutcomeResolution::query()->firstOrFail();
    expect((string) $audit->resolved_by)->toBe((string) $this->operator->id)->and($audit->evidence)->toBe($this->input['evidence']);
});

test('confirmed non-submission refunds once and conflicting later outcomes are rejected', function () {
    $service = app(CommunitySmsReconciler::class);
    foreach ([1, 2] as $attempt) {
        $service->resolve($this->operator, $this->tenant, $this->reservation->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']);
    }
    expect(app(SmsAllowance::class)->remaining($this->tenant))->toBe(10)->and(MessageOutcomeResolution::query()->count())->toBe(1);
    expect(fn () => $service->resolve($this->operator, $this->tenant, $this->reservation->id, 'confirmed_accepted', $this->input['evidence'], $this->input['reason']))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('evidence is mandatory and tenant-bound source IDs cannot cross organisations', function () {
    $this->actingAs($this->operator)->post(route('admin.messages.reconciliation.resolve'), array_replace($this->input, ['evidence' => '']))->assertSessionHasErrors('evidence');
    expect(fn () => app(CommunitySmsReconciler::class)->resolve($this->operator, Tenant::factory()->create(), $this->reservation->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(MessageOutcomeResolution::query()->count())->toBe(0);
});

test('the operator screen lists uncertain sources and records a confirmed resolution', function () {
    $this->actingAs($this->operator)->get(route('admin.messages.reconciliation.index'))->assertOk()->assertSee('Uncertain message outcomes')->assertSee($this->reservation->id);
    $this->actingAs($this->operator)->post(route('admin.messages.reconciliation.resolve'), $this->input)->assertRedirect();
    expect($this->reservation->fresh()->status)->toBe('accepted');
});

test('unknown Community email outcomes share the same audited resolution process', function () {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'partial', 'selection_complete' => true]);
    $receipt = app(EmailAllowance::class)->reserve($this->tenant, 1);
    $delivery = CommunityMessageDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'message_id' => $message->id, 'channel' => 'email', 'status' => 'unknown', 'reason' => 'outcome_unknown', 'credit_receipt_id' => $receipt->id]);
    app(CommunitySmsReconciler::class)->resolveSource($this->operator, $this->tenant, 'community_email', $delivery->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']);
    expect($delivery->fresh()->status)->toBe('failed')->and(app(EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

test('unknown legacy reminder reservations can be resolved without replay', function () {
    $receipt = app(SmsAllowance::class)->reserve($this->tenant, 1);
    $reminder = DuesReminder::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'reserved', 'reason' => 'outcome_unknown', 'credit_receipt_id' => $receipt->id]);
    app(CommunitySmsReconciler::class)->resolveSource($this->operator, $this->tenant, 'legacy_reminder', $reminder->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']);
    expect($reminder->fresh()->status)->toBe('failed')->and(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
});

test('accepted SMS review updates the related message summary', function () {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'partial', 'selection_complete' => true]);
    $delivery = CommunityMessageDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'message_id' => $message->id, 'channel' => 'sms', 'address' => '+233241234567', 'status' => 'unknown', 'sms_reservation_id' => $this->reservation->id]);
    $this->reservation->update(['source_id' => $delivery->id]);
    app(CommunitySmsReconciler::class)->resolve($this->operator, $this->tenant, $this->reservation->id, 'confirmed_accepted', $this->input['evidence'], $this->input['reason']);
    expect($delivery->fresh()->status)->toBe('accepted')->and($message->fresh()->status)->toBe('completed');
});

test('an unknown event email can release its exact held credit', function () {
    $receipt = app(EmailAllowance::class)->reserve($this->tenant, 1);
    $event = App\Models\Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $blast = App\Models\EventBlast::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $event->id]);
    $claim = App\Models\EventBlastEmailClaim::factory()->create(['tenant_id' => $this->tenant->id, 'blast_id' => $blast->id, 'status' => 'unknown', 'credit_receipt_id' => $receipt->id]);
    app(CommunitySmsReconciler::class)->resolveSource($this->operator, $this->tenant, 'event_email', $claim->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']);
    expect($claim->fresh()->status)->toBe('failed')->and(app(EmailAllowance::class)->remaining($this->tenant))->toBe(10);
});

test('linked reminder credits cannot be refunded through the legacy source path', function () {
    $reminder = DuesReminder::factory()->create(['tenant_id' => $this->tenant->id, 'status' => 'reserved', 'reason' => 'outcome_unknown', 'credit_receipt_id' => $this->reservation->credit_receipt_id]);
    $this->reservation->update(['source_type' => 'dues_reminder', 'source_id' => $reminder->id]);
    expect(fn () => app(CommunitySmsReconciler::class)->resolveSource($this->operator, $this->tenant, 'legacy_reminder', $reminder->id, 'confirmed_not_submitted', $this->input['evidence'], $this->input['reason']))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(app(SmsAllowance::class)->remaining($this->tenant))->toBe(9);
});

test('operator review includes a masked destination and exact source reference', function () {
    $message = CommunityMessage::factory()->create(['tenant_id' => $this->tenant->id]);
    $delivery = CommunityMessageDelivery::factory()->create(['tenant_id' => $this->tenant->id, 'message_id' => $message->id, 'channel' => 'sms', 'address' => '+233241234567', 'status' => 'unknown']);
    $this->reservation->update(['source_id' => $delivery->id]);
    $this->actingAs($this->operator)->get(route('admin.messages.reconciliation.index'))->assertOk()->assertSee('+233…4567')->assertSee($delivery->id);
});

test('the audit retains its operator reference if the account is later removed', function () {
    $reference = 'user:'.$this->operator->id;
    app(CommunitySmsReconciler::class)->resolve($this->operator, $this->tenant, $this->reservation->id, 'confirmed_accepted', $this->input['evidence'], $this->input['reason']);
    $this->operator->delete();
    expect(MessageOutcomeResolution::query()->firstOrFail()->operator_reference)->toBe($reference);
});
