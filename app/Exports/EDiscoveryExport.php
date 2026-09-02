<?php

declare(strict_types=1);

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

final readonly class EDiscoveryExport implements FromCollection, WithHeadings, WithMapping
{
    use Exportable;

    /**
     * @param  Collection<int, array{type: string, content: string, meeting_id: string, meeting_title: string, date: string}>  $results
     */
    public function __construct(private Collection $results) {}

    public function collection(): Collection
    {
        return $this->results;
    }

    public function headings(): array
    {
        return [
            'Type',
            'Content',
            'Meeting ID',
            'Meeting Title',
            'Date',
        ];
    }

    public function map($row): array
    {
        return [
            $row['type'],
            $row['content'],
            $row['meeting_id'],
            $row['meeting_title'],
            $row['date'],
        ];
    }
}
