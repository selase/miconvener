<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantNotificationSetting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'tenant_id',
        'email_monthly_limit',
        'email_used_this_month',
        'sms_enabled',
        'whatsapp_enabled',
        'overage_billing_enabled',
        'sms_cost_rate',
        'whatsapp_cost_rate',
        'email_overage_rate',
        'anti_abuse_cooldown_minutes',
        'month_reset_at',
    ];

    protected $casts = [
        'email_monthly_limit' => 'integer',
        'email_used_this_month' => 'integer',
        'sms_enabled' => 'boolean',
        'whatsapp_enabled' => 'boolean',
        'overage_billing_enabled' => 'boolean',
        'sms_cost_rate' => 'integer',
        'whatsapp_cost_rate' => 'integer',
        'email_overage_rate' => 'integer',
        'anti_abuse_cooldown_minutes' => 'integer',
        'month_reset_at' => 'datetime',
    ];

    /**
     * Resolve or instantiate the settings record for a given tenant.
     */
    public static function forTenant(string $tenantId): self
    {
        return self::firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'email_monthly_limit' => 2500,
                'email_used_this_month' => 0,
                'sms_enabled' => false,
                'whatsapp_enabled' => false,
                'overage_billing_enabled' => false,
                'sms_cost_rate' => 25,
                'whatsapp_cost_rate' => 40,
                'email_overage_rate' => 3,
                'anti_abuse_cooldown_minutes' => 60,
                'month_reset_at' => now()->startOfMonth(),
            ]
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Check if a channel send is permitted under tenant settings and quotas.
     */
    public function canSend(string $channel): bool
    {
        // Auto-reset monthly counter if in new month
        $this->checkAndResetMonthly();

        if ($channel === 'sms' && ! $this->sms_enabled) {
            return false;
        }

        if ($channel === 'whatsapp' && ! $this->whatsapp_enabled) {
            return false;
        }

        if ($channel === 'email') {
            if ($this->email_used_this_month < $this->email_monthly_limit) {
                return true;
            }

            // Exceeded limit — only permitted if overage billing is active
            return $this->overage_billing_enabled;
        }

        return true;
    }

    /**
     * Record a send and calculate any cost charged.
     */
    public function recordSend(string $channel): int
    {
        $this->checkAndResetMonthly();
        $cost = 0;

        if ($channel === 'email') {
            $this->increment('email_used_this_month');
            if ($this->email_used_this_month > $this->email_monthly_limit && $this->overage_billing_enabled) {
                $cost = (int) $this->email_overage_rate;
            }
        } elseif ($channel === 'sms') {
            $cost = (int) $this->sms_cost_rate;
        } elseif ($channel === 'whatsapp') {
            $cost = (int) $this->whatsapp_cost_rate;
        }

        return $cost;
    }

    /**
     * Reverse a send that was counted but never delivered.
     *
     * recordSend() increments before delivery is attempted, so a throw leaves
     * the allowance spent on a message nobody received. Only email is metered;
     * the other channels carry a per-message rate and no counter.
     */
    public function refundSend(string $channel): void
    {
        if ($channel !== 'email') {
            return;
        }

        if ((int) $this->email_used_this_month <= 0) {
            return;
        }

        // The in-memory guard above is a fast path, not the enforcement: two
        // concurrent refunds could both pass it while the column is still 1.
        // Re-checking the floor in the WHERE clause makes the decrement itself
        // a no-op once another process has already brought it to zero.
        self::query()
            ->whereKey($this->getKey())
            ->where('email_used_this_month', '>', 0)
            ->decrement('email_used_this_month');

        $this->refresh();
    }

    private function checkAndResetMonthly(): void
    {
        if (! $this->month_reset_at || $this->month_reset_at->isBefore(now()->startOfMonth())) {
            $this->update([
                'email_used_this_month' => 0,
                'month_reset_at' => now()->startOfMonth(),
            ]);
        }
    }
}
