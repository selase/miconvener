<?php

declare(strict_types=1);

namespace App\Traits;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

trait SpatieActivityLogs
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->setDescriptionForEvent(fn (string $eventName): string => "This model has been {$eventName}")
            ->logAll()
            ->logOnlyDirty()
            ->useLogName('system');
    }
}
