<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Feature;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Badges\BadgeTemplateReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class PaidBadgeTemplatesCommand extends Command
{
    protected $signature = 'badges:paid-templates {tenant : Buying organisation slug} {--enable : Enable new purchases after checking readiness} {--off : Disable new purchases}';

    protected $description = 'Check paid template readiness without changes, or explicitly enable or pause one buying organisation.';

    public function handle(): int
    {
        $buyer = Tenant::query()->where('slug', $this->argument('tenant'))->first();
        if ($buyer === null || ($this->option('enable') && $this->option('off'))) {
            $this->error('Choose an existing organisation and one operation.');

            return self::FAILURE;
        }
        if (! $this->option('off')) {
            try {
                app(BadgeTemplateReadiness::class)->agreement($buyer, false);
                if (Permission::query()->where('guard_name', 'web')->whereIn('name', ['purchase badge-templates', 'manage template refunds'])->count() !== 2
                    || Role::query()->whereNull('tenant_id')->where('guard_name', 'web')->whereIn('name', ['Superadmin', 'Org Superadmin'])->count() !== 2) {
                    $this->error('Required permissions or built-in role definitions are missing. Verify role grants before enabling.');

                    return self::FAILURE;
                }
            } catch (ValidationException $error) {
                $this->error($error->getMessage());

                return self::FAILURE;
            }
        }
        if (! $this->option('off') && ! $this->option('enable')) {
            $this->info('Readiness passed. No settings changed. Verify purchaser role grants and complete owner-approved acceptance before enabling.');

            return self::SUCCESS;
        }
        Feature::query()->firstOrCreate(['slug' => 'paid_badge_templates'], ['name' => 'Paid badge templates', 'type' => 'boolean', 'description' => 'Explicit organisation pilot for paid event design licences.']);
        $buyer->features()->updateOrCreate(['feature_key' => 'paid_badge_templates'], ['enabled' => ! $this->option('off'), 'meta' => ['source' => 'admin_override']]);
        Cache::forget('tenant_'.$buyer->id.'_feature_paid_badge_templates');
        $this->info('New paid template purchases '.($this->option('off') ? 'paused' : 'enabled').' for '.$buyer->slug.'. Existing rights and financial recovery remain available.');

        return self::SUCCESS;
    }
}
