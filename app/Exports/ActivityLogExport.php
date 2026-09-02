<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Spatie\Activitylog\Models\Activity;

final class ActivityLogExport implements FromQuery, WithHeadings, WithMapping
{
    use Exportable;

    public function __construct(
        private ?string $tenantId = null,
        private ?string $causerId = null,
        private ?string $subjectType = null,
        private ?string $dateFrom = null,
        private ?string $dateTo = null,
    ) {}

    public function query()
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->latest();

        if ($this->tenantId) {
            $query->where('properties->tenant_id', $this->tenantId);
        }

        if ($this->causerId) {
            $query->where('causer_id', $this->causerId);
        }

        if ($this->subjectType) {
            $query->where('subject_type', $this->subjectType);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Date',
            'Log Name',
            'Description',
            'Subject Type',
            'Subject ID',
            'Causer',
            'Properties',
        ];
    }

    public function map($activity): array
    {
        return [
            $activity->id,
            $activity->created_at->format('Y-m-d H:i:s'),
            $activity->log_name,
            $activity->description,
            $activity->subject_type,
            $activity->subject_id,
            $activity->causer?->displayName() ?? 'System',
            json_encode($activity->properties),
        ];
    }
}
