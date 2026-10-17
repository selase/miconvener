<?php

declare(strict_types=1);

namespace App\Models;

use App\Scopes\TenantScope;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property \Carbon\CarbonImmutable|null $giving_event_cutover_at */
final class CommunitySettings extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<\Database\Factories\CommunitySettingsFactory> */
    use HasFactory;

    use HasUuids;

    protected $connection = 'landlord';

    protected $fillable = [
        'giving_event_cutover_at', 'join_mode', 'public_join_enabled', 'public_membership_type_id',
        'reminders_enabled', 'reminder_offsets', 'frequent_digest', 'sms_monthly_limit',
        'tenant_id',
        'timezone',
        'week_starts_on',
        'financial_year_start_month',
        'generate_days_ahead',
        'charge_current_period_on_join',
    ];

    protected $attributes = [
        'join_mode' => 'approval_required',
        'public_join_enabled' => false,
        'reminders_enabled' => true,
        'reminder_offsets' => '{"before":7,"on_day":0,"after_7":7,"after_30":30}',
        'frequent_digest' => 'daily',
        'timezone' => 'Africa/Accra',
        'week_starts_on' => 1,
        'financial_year_start_month' => 1,
        'generate_days_ahead' => 7,
        'charge_current_period_on_join' => false,
    ];

    protected $casts = [
        'giving_event_cutover_at' => 'immutable_datetime',
        'public_join_enabled' => 'boolean',
        'reminders_enabled' => 'boolean',
        'reminder_offsets' => 'array',
        'sms_monthly_limit' => 'integer',
        'week_starts_on' => 'integer',
        'financial_year_start_month' => 'integer',
        'generate_days_ahead' => 'integer',
        'charge_current_period_on_join' => 'boolean',
    ];

    /** Reading defaults must not create settings as a side effect. */
    public static function for(Tenant $tenant): self
    {
        return self::withoutGlobalScope(TenantScope::class)
            ->firstOrNew(['tenant_id' => $tenant->id]);
    }
}
