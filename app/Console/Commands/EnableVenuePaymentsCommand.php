<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\StoreCommercialAgreement;
use App\Models\Tenant;
use App\Services\Marketplace\VenuePaymentRollout;
use App\Services\Marketplace\VenueRequestRollout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class EnableVenuePaymentsCommand extends Command
{
    protected $signature = 'venue:enable-payments {tenant : Organisation slug} {--off : Stop starting new venue payments}';

    protected $description = 'Enable or disable new venue payments for one organisation. Off by default; money already taken is always recorded.';

    public function handle(): int
    {
        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }

        if (! $this->option('off') && ($missing = $this->missingPrerequisites($tenant)) !== []) {
            $this->error('Not enabled. Still needed: '.implode('; ', $missing).'.');

            return self::FAILURE;
        }

        Feature::query()->firstOrCreate(['slug' => VenuePaymentRollout::FEATURE], [
            'name' => 'Venue payments',
            'type' => 'boolean',
            'description' => 'Final venue quotes and hosted payments for approved bookings. Off unless an administrator enables it.',
        ]);
        $tenant->features()->updateOrCreate(['feature_key' => VenuePaymentRollout::FEATURE], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$tenant->id.'_feature_'.VenuePaymentRollout::FEATURE);
        $this->info('Venue payments '.($this->option('off') ? 'disabled' : 'enabled').' for '.$tenant->slug.'.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function missingPrerequisites(Tenant $tenant): array
    {
        $missing = [];
        if (! $tenant->isPlatformDefaultSettlement()) {
            $missing[] = 'the organisation must settle through the platform account';
        }
        if (! filled(config('services.settlement.paystack.secret_key'))) {
            $missing[] = 'the platform payment account is not configured';
        }
        if (! $tenant->features()->where('feature_key', VenueRequestRollout::FEATURE)->where('enabled', true)->exists()) {
            $missing[] = 'approval-first venue requests must be enabled first (venue:enable-requests)';
        }
        $agreement = StoreCommercialAgreement::query()->where('tenant_id', $tenant->id)->where('offering_scope', StoreCommercialAgreement::SCOPE_RESERVATION)->where('approved_at', '<=', now())->exists();
        if (! $agreement) {
            $missing[] = 'an approved venue (reservation) commercial agreement';
        }

        return $missing;
    }
}
