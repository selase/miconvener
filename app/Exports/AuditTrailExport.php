<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Spatie\Activitylog\Models\Activity;

final class AuditTrailExport implements FromCollection
{
    /**
     * @return Collection<int, Activity>
     */
    public function collection(): Collection
    {
        return Activity::all();
    }
}
