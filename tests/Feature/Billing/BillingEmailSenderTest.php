<?php

declare(strict_types=1);

use App\Models\BillingEmail;
use App\Models\Tenant;
use App\Services\Billing\BillingEmailSender;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => refreshTenantDatabases());

function probeBillingMailable(): Mailable
{
    return new class extends Mailable
    {
        public function build(): static
        {
            return $this->subject('Probe')->html('<p>probe</p>');
        }
    };
}

test('an email is queued once per type and key, however often it is requested', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $sender = app(BillingEmailSender::class);

    expect($sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, 'receipt:ref_1', ['a@example.com'], probeBillingMailable()))->toBeTrue()
        ->and($sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, 'receipt:ref_1', ['a@example.com'], probeBillingMailable()))->toBeFalse();

    Mail::assertQueuedCount(1);
    expect(BillingEmail::query()->count())->toBe(1);
});

test('the same key under a different type is a different email', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $sender = app(BillingEmailSender::class);

    $sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDING, 'SUB_1', ['a@example.com'], probeBillingMailable());
    $sender->send($tenant, BillingEmail::TYPE_SUBSCRIPTION_ENDED, 'SUB_1', ['a@example.com'], probeBillingMailable());

    Mail::assertQueuedCount(2);
});

test('recipients are lower-cased and de-duplicated, and none means nothing is sent', function (): void {
    Mail::fake();
    $tenant = Tenant::factory()->create();
    $sender = app(BillingEmailSender::class);

    $sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, 'receipt:ref_2', ['Owner@Example.com', ' owner@example.com', ''], probeBillingMailable());
    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_2')->value('recipients'))->toBe(['owner@example.com']);

    expect($sender->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, 'receipt:ref_3', [null, ''], probeBillingMailable()))->toBeFalse();
    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_3')->exists())->toBeFalse();
    Mail::assertQueuedCount(1);
});

test('if queueing fails the claim is released so the email can be retried', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));
    $tenant = Tenant::factory()->create();

    expect(fn () => app(BillingEmailSender::class)->send($tenant, BillingEmail::TYPE_PAYMENT_RECEIPT, 'receipt:ref_4', ['a@example.com'], probeBillingMailable()))
        ->toThrow(RuntimeException::class, 'queue down');

    expect(BillingEmail::query()->where('dedupe_key', 'receipt:ref_4')->exists())->toBeFalse();
});
