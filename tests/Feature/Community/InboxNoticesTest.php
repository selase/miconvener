<?php

declare(strict_types=1);

use App\Jobs\SendDuesReminders;
use App\Models\CommunityInboxItem;
use App\Models\CommunitySettings;
use App\Models\DuesCharge;
use App\Services\Dues\DuesCharges;
use App\Services\Dues\DuesPaymentConfirmer;
use App\Services\Dues\DuesReminders;

require_once dirname(__DIR__, 2).'/Fixtures/community/message-test-setup.php';
beforeEach(function (): void {
    setupCommunityMessagesTest($this);
    foreach (['community_app', 'community_inbox'] as $key) {
        $this->tenant->features()->create(['feature_key' => $key, 'enabled' => true]);
    }
    $this->charge = DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-10-08', 'amount' => 12000, 'currency' => 'GHS']);
});

it('queues one day notice before transport and keeps it when SMS has no credit', function (): void {
    $this->tenant->features()->where('feature_key', 'sms_credits')->firstOrFail()->update(['meta' => ['type' => 'limit', 'value' => 0]]);
    $job = new SendDuesReminders($this->tenant->id);
    $job->handle(app(DuesReminders::class));
    $job->handle(app(DuesReminders::class));
    app(DuesReminders::class)->remindArrears($this->tenant, (int) $this->owner->id);
    expect(CommunityInboxItem::query()->where('kind', 'dues_reminder')->count())->toBe(1);
});

it('does not backfill a reminder queued while inbox was off or invent a cadence-mismatched notice', function (): void {
    $this->tenant->features()->where('feature_key', 'community_inbox')->update(['enabled' => false]);
    $job = new SendDuesReminders($this->tenant->id);
    $this->tenant->features()->where('feature_key', 'community_inbox')->update(['enabled' => true]);
    $job->handle(app(DuesReminders::class));
    expect(CommunityInboxItem::query()->count())->toBe(0);
    $this->charge->update(['due_on' => '2026-10-12']);
    (new SendDuesReminders($this->tenant->id))->handle(app(DuesReminders::class));
    expect(CommunityInboxItem::query()->count())->toBe(0);
});

it('snapshots periods and amounts by currency without contacts or bearer tokens', function (): void {
    DuesCharge::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'membership_type_id' => $this->member->membership_type_id, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_on' => '2026-10-08', 'amount' => 500, 'currency' => 'USD']);
    app(App\Services\Community\MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $this->member->email);
    (new SendDuesReminders($this->tenant->id))->handle(app(DuesReminders::class));
    $item = CommunityInboxItem::query()->sole();
    expect($item->body)->toContain('GHS 120.00', 'USD 5.00', '2026-10-08')->not->toContain($this->member->pay_token, $this->member->email);
    $this->charge->update(['amount' => 50000, 'status' => 'waived']);
    expect($item->fresh()->body)->toBe($item->body);
});

it('disabled reminders create no notice and marketing opt-out never stops service receipts', function (): void {
    CommunitySettings::for($this->tenant)->fill(['reminders_enabled' => false])->save();
    (new SendDuesReminders($this->tenant->id))->handle(app(DuesReminders::class));
    expect(CommunityInboxItem::query()->count())->toBe(0);
    app(App\Services\Community\MessagingPreferences::class)->disableMarketing($this->tenant, 'email', $this->member->email);
    app(DuesCharges::class)->recordOffline($this->charge, (int) $this->owner->id, 'Cash received');
    expect(CommunityInboxItem::query()->where('kind', 'dues_receipt')->count())->toBe(1)
        ->and(App\Models\LedgerTransaction::query()->count())->toBe(0);
});

it('records one online receipt transactionally and no extra on callback replay', function (): void {
    $data = ['metadata' => ['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'charge_ids' => [$this->charge->id]], 'amount' => 12000, 'fees' => 0, 'currency' => 'GHS', 'settled_by' => DuesPaymentConfirmer::SETTLED_BY_PLATFORM];
    $service = app(DuesPaymentConfirmer::class);
    $service->confirm('inbox-receipt-reference', $data);
    $service->confirm('inbox-receipt-reference', $data);
    expect(CommunityInboxItem::query()->where('kind', 'dues_receipt')->count())->toBe(1);
    expect(CommunityInboxItem::query()->sole()->source_id)->toBe('inbox-receipt-reference');
});

it('does not create receipt notices for rejected payments or duplicate cash attempts', function (): void {
    app(DuesPaymentConfirmer::class)->confirm('wrong-amount', ['metadata' => ['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'charge_ids' => [$this->charge->id]], 'amount' => 1, 'fees' => 0, 'currency' => 'GHS', 'settled_by' => 'platform']);
    expect(CommunityInboxItem::query()->count())->toBe(0);
    app(DuesCharges::class)->recordOffline($this->charge, (int) $this->owner->id, 'Cash received');
    expect(fn () => app(DuesCharges::class)->recordOffline($this->charge, (int) $this->owner->id, 'Retry'))->toThrow(DomainException::class);
    expect(CommunityInboxItem::query()->count())->toBe(1);
});

it('keeps the paid receipt when email queueing fails and preserves the callback error contract', function (): void {
    Illuminate\Support\Facades\Mail::shouldReceive('to')->andThrow(new RuntimeException('Queue unavailable'));
    $data = ['metadata' => ['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'charge_ids' => [$this->charge->id]], 'amount' => 12000, 'fees' => 0, 'currency' => 'GHS', 'settled_by' => 'platform'];
    expect(fn () => app(DuesPaymentConfirmer::class)->confirm('receipt-queue-failure', $data))->toThrow(RuntimeException::class);
    expect($this->charge->fresh()->status->value)->toBe('paid')
        ->and(CommunityInboxItem::query()->where('kind', 'dues_receipt')->count())->toBe(1);
});

it('rolls back a cash receipt with its paid state when the transaction is aborted', function (): void {
    expect(fn () => $this->charge->getConnection()->transaction(function (): void {
        app(DuesCharges::class)->recordOffline($this->charge, (int) $this->owner->id, 'Cash received');
        throw new RuntimeException('Abort test transaction');
    }))->toThrow(RuntimeException::class);
    expect($this->charge->fresh()->status->value)->toBe('open')->and(CommunityInboxItem::query()->count())->toBe(0);
});

it('never reconstructs an old same-day reminder when the inbox is enabled later', function (): void {
    App\Models\DuesReminder::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->member->id, 'channel' => 'email', 'sent_for_date' => '2026-10-15', 'status' => 'sent', 'charge_ids' => [$this->charge->id]]);
    (new SendDuesReminders($this->tenant->id))->handle(app(DuesReminders::class));
    expect(CommunityInboxItem::query()->count())->toBe(0);
});
