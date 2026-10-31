<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Jobs\DeliverGivingReceipt;
use App\Mail\GivingReceipt;
use App\Models\EventContribution;
use App\Models\GivingGift;
use App\Models\GivingReceiptNotice;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

final class GivingReceipts
{
    /** Called inside the source confirmation transaction, before releasing its lock. */
    public function capture(GivingSource $source): GivingReceiptNotice
    {
        abort_unless(in_array($source->model->status, ['completed', EventContribution::STATUS_REFUNDED], true), 409);
        $receipt = app(GivingHistory::class)->receiptSnapshot($source);
        $email = $source->model instanceof GivingGift ? $source->model->payer_email : $source->model->contributor_email;
        $email = is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower(mb_trim($email)) : null;
        $notice = GivingReceiptNotice::withoutGlobalScopes()->firstOrCreate(
            ['payment_key' => $source->key()],
            ['tenant_id' => $source->fund->tenant_id, 'fund_id' => $source->fund->id, ...$source->columns(), 'receipt_number' => $receipt['receipt_number'], 'snapshot' => $receipt, 'destination' => $email, 'state' => $email === null ? 'no_address' : 'queued'],
        );
        if ($notice->wasRecentlyCreated && $email !== null) {
            $source->model->getConnection()->afterCommit(fn () => DeliverGivingReceipt::dispatch($notice->id));
        }

        return $notice;
    }

    public function deliver(string $id): void
    {
        $notice = GivingReceiptNotice::withoutGlobalScopes()->findOrFail($id);
        $claimed = GivingReceiptNotice::withoutGlobalScopes()->whereKey($id)->whereIn('state', ['queued', 'retryable'])->whereNotNull('destination')->update(['state' => 'sending']);
        if ($claimed !== 1) {
            return;
        }
        try {
            Mail::to($notice->destination)->send(new GivingReceipt($notice));
            $notice->update(['state' => 'sent', 'sent_at' => now()]);
        } catch (Throwable) {
            $notice->update(['state' => 'retryable']);
            throw new RuntimeException('Contribution receipt delivery is temporarily unavailable.');
        }
    }

    public function retryQueued(): void
    {
        GivingReceiptNotice::withoutGlobalScopes()->whereIn('state', ['queued', 'retryable'])->whereNotNull('destination')->orderBy('id')->limit(100)->get()->each(fn (GivingReceiptNotice $notice) => DeliverGivingReceipt::dispatch($notice->id));
    }
}
