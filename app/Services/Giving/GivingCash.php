<?php

declare(strict_types=1);

namespace App\Services\Giving;

use App\Models\CommunitySettings;
use App\Models\GivingAdjustment;
use App\Models\GivingFund;
use App\Models\GivingGift;
use App\Models\Member;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class GivingCash
{
    public function __construct(private readonly GivingProgress $progress) {}

    /** @param array<string, mixed> $validated */
    public function record(GivingFund $fund, User $actor, array $validated, string $submissionKey): GivingGift
    {
        $this->authorize($fund, $actor);
        abort_unless(app(GivingRollout::class)->enabled($fund->tenant), 404);
        $this->validOperationKey($submissionKey);

        return $fund->getConnection()->transaction(function () use ($fund, $actor, $validated, $submissionKey): GivingGift {
            $fund = GivingFund::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->lockForUpdate()->findOrFail($fund->id);
            $existing = GivingGift::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('submission_key', $submissionKey)->first();
            if ($existing !== null) {
                if ($existing->fund_id !== $fund->id || $existing->amount !== ($validated['amount'] ?? null) || $existing->currency !== ($validated['currency'] ?? null) || $existing->source !== ($validated['source'] ?? null)) {
                    throw ValidationException::withMessages(['submission_key' => 'This submission already records a different contribution.']);
                }

                return $existing;
            }
            $this->progress->assertOpen($fund);
            $amount = $validated['amount'] ?? null;
            $source = $validated['source'] ?? '';
            if (! is_int($amount) || $amount < $fund->minimum_amount || $amount > 999999999999 || ($validated['currency'] ?? null) !== $fund->currency || ! in_array($source, ['recorded_cash', 'bulk_cash'], true)) {
                throw ValidationException::withMessages(['amount' => 'Enter a valid cash amount in the fund currency.']);
            }
            if ($source === 'bulk_cash' && collect(['member_id', 'payer_name', 'payer_email', 'payer_phone', 'reuse_giver_key'])->contains(fn (string $field): bool => ! empty($validated[$field]))) {
                throw ValidationException::withMessages(['source' => 'Offering batches have no individual payer or member.']);
            }
            $memberId = $validated['member_id'] ?? null;
            if ($memberId !== null && ! Member::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->whereKey($memberId)->exists()) {
                throw ValidationException::withMessages(['member_id' => 'Choose a member of this organisation.']);
            }
            $email = ! empty($validated['payer_email']) ? mb_strtolower(mb_trim($validated['payer_email'])) : null;
            $key = $source === 'bulk_cash' ? null : $this->giverKey($fund, $validated, $email, $memberId);
            $localCollected = $validated['collected_at'] ?? null;
            $collected = $localCollected === null ? CarbonImmutable::now() : CarbonImmutable::parse($localCollected, CommunitySettings::for($fund->tenant)->timezone)->utc();
            $gift = GivingGift::withoutGlobalScopes()->create([
                'tenant_id' => $fund->tenant_id, 'fund_id' => $fund->id, 'member_id' => $memberId,
                'payer_name' => $validated['payer_name'] ?? null, 'payer_email' => $email, 'payer_phone' => $validated['payer_phone'] ?? null,
                'payer_email_hash' => $email === null ? null : GivingIdentity::emailKey($fund->tenant, $email),
                'giver_key' => $key, 'is_anonymous' => (bool) ($validated['is_anonymous'] ?? false),
                'amount' => $amount, 'currency' => $fund->currency, 'source' => $source, 'status' => 'completed',
                'recorded_by' => $actor->id, 'collected_at' => $collected, 'paid_at' => $collected,
                'gateway_fee_amount' => 0, 'commission_amount' => 0, 'net_amount' => $amount, 'submission_key' => $submissionKey,
            ]);
            app(GivingReceipts::class)->capture(app(GivingSources::class)->find($fund->tenant, 'gift', $gift->id));
            $this->progress->closeIfTargetReached($fund);

            return $gift;
        });
    }

    public function correct(GivingGift $gift, User $actor, int $reduction, string $reason, string $operationKey): GivingAdjustment
    {
        $this->authorize($gift->fund, $actor);
        $this->validOperationKey($operationKey);

        return $gift->getConnection()->transaction(function () use ($gift, $actor, $reduction, $reason, $operationKey): GivingAdjustment {
            GivingFund::withoutGlobalScopes()->where('tenant_id', $gift->tenant_id)->lockForUpdate()->findOrFail($gift->fund_id);
            $gift = GivingGift::withoutGlobalScopes()->where('tenant_id', $gift->tenant_id)->lockForUpdate()->findOrFail($gift->id);
            $existing = GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $gift->tenant_id)->where('operation_key', $operationKey)->first();
            if ($existing !== null) {
                if ($existing->gift_id !== $gift->id || $existing->principal !== $reduction || $existing->reason !== mb_trim($reason)) {
                    throw ValidationException::withMessages(['operation_key' => 'This operation already records a different correction.']);
                }

                return $existing;
            }
            $corrected = (int) GivingAdjustment::withoutGlobalScopes()->where('tenant_id', $gift->tenant_id)->where('gift_id', $gift->id)->where('status', 'confirmed')->sum('principal');
            if (! in_array($gift->source, ['recorded_cash', 'bulk_cash'], true) || $gift->status !== 'completed' || $reduction <= 0 || $reduction > $gift->amount - $corrected || mb_trim($reason) === '') {
                throw ValidationException::withMessages(['principal' => 'The correction must not exceed the uncorrected recorded cash.']);
            }

            return GivingAdjustment::withoutGlobalScopes()->create([
                'tenant_id' => $gift->tenant_id, 'fund_id' => $gift->fund_id, 'gift_id' => $gift->id,
                'principal' => $reduction, 'commission_reversal' => 0, 'gateway_credit' => 0, 'kind' => 'cash_correction',
                'status' => 'confirmed', 'reason' => mb_trim($reason), 'actor_id' => $actor->id,
                'operation_key' => $operationKey, 'confirmed_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $input */
    private function giverKey(GivingFund $fund, array $input, ?string $email, ?string $memberId): ?string
    {
        if (! empty($input['reuse_giver_key'])) {
            $key = $input['reuse_giver_key'];
            if (! GivingGift::withoutGlobalScopes()->where('tenant_id', $fund->tenant_id)->where('giver_key', $key)->where('status', 'completed')->exists()) {
                throw ValidationException::withMessages(['reuse_giver_key' => 'Select a previously recorded giver from this organisation.']);
            }

            return $key;
        }
        if ($email !== null && ($input['identity_confirmed'] ?? false) === true) {
            return GivingIdentity::emailKey($fund->tenant, $email);
        }
        if ($memberId !== null) {
            return 'member:'.$memberId;
        }

        return ! empty($input['payer_name']) ? 'manual:'.Str::uuid() : null;
    }

    private function authorize(GivingFund $fund, User $actor): void
    {
        abort_unless($actor->tenant_id === $fund->tenant_id || $fund->tenant->users()->where('users.id', $actor->id)->exists(), 403);
        Gate::forUser($actor)->authorize('manage giving');
        Gate::forUser($actor)->authorize('read finance');
    }

    private function validOperationKey(string $key): void
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['operation_key' => 'Use a valid submission identifier.']);
        }
    }
}
