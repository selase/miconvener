<?php

declare(strict_types=1);

namespace App\Console\Commands\Dues;

use App\Enum\TenantStatusEnum;
use App\Jobs\SendDuesReminders;
use App\Models\CommunitySettings;
use App\Models\Tenant;
use App\Services\Dues\DuesScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessDuesCommand extends Command
{
    protected $signature = 'dues:process {--tenant= : Organisation slug or ID} {--date= : Local date YYYY-MM-DD}';

    protected $description = 'Generate eligible member dues in each organisation local timezone';

    public function handle(DuesScheduler $scheduler): int
    {
        $date = $this->option('date');
        if ($date !== null && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ! checkdate((int) mb_substr($date, 5, 2), (int) mb_substr($date, 8, 2), (int) mb_substr($date, 0, 4)))) {
            $this->error('The date must be a valid YYYY-MM-DD date.');

            return self::FAILURE;
        }

        $selection = $this->option('tenant');
        $tenants = Tenant::query()->where('status', TenantStatusEnum::ACTIVE)->when($selection, fn ($query) => $query->where(fn ($q) => $q->where('slug', $selection)->orWhereRaw('id::text = ?', [$selection])))->get();
        if ($selection && $tenants->isEmpty()) {
            $this->error('Organisation not found.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($tenants as $tenant) {
            if (! $tenant->communityEnabled()) {
                continue;
            }
            try {
                $settings = CommunitySettings::for($tenant);
                $localNow = CarbonImmutable::now($settings->timezone);
                if ($date === null && $localNow->format('H:i') < '00:30') {
                    continue;
                }
                $today = $date !== null ? CarbonImmutable::parse($date, $settings->timezone) : $localNow->startOfDay();
                CommunitySettings::withoutGlobalScopes()->createOrFirst(['tenant_id' => $tenant->id]);
                $count = DB::connection('landlord')->transaction(function () use ($tenant, $scheduler, $today, $date): int {
                    $locked = CommunitySettings::withoutGlobalScopes()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
                    if ($date === null && $locked->getRawOriginal('last_generated_on') === $today->toDateString()) {
                        return 0;
                    }
                    $count = $scheduler->generateFor($tenant, $today);
                    $locked->forceFill(['last_generated_on' => $today->toDateString()])->save();

                    return $count;
                });
                SendDuesReminders::dispatch($tenant->id, localDate: $today->toDateString())->afterCommit();
                $this->info("{$tenant->slug}: {$count} charges created; reminders queued.");
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Dues processing failed for {$tenant->slug}; see the application log.");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
