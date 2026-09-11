<?php

declare(strict_types=1);

namespace App\Services\Programme;

use App\Models\Event;
use App\Models\EventAbstract;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;

final class AbstractBookService
{
    /**
     * @return array<string, mixed>
     */
    public function getBookData(Event $event): array
    {
        $acceptedAbstracts = $event->abstracts()
            ->whereIn('status', [EventAbstract::STATUS_ACCEPTED_ORAL, EventAbstract::STATUS_ACCEPTED_POSTER])
            ->with(['authors', 'sessions'])
            ->orderBy('track')
            ->orderBy('title')
            ->get();

        $abstractsByTrack = $acceptedAbstracts->groupBy(fn ($a) => $a->track ?? 'General');

        $sessions = $event->sessions()
            ->with(['speakers', 'abstract'])
            ->orderBy('starts_at')
            ->orderBy('sort_order')
            ->get();

        return [
            'event' => $event,
            'abstracts' => $acceptedAbstracts,
            'abstracts_by_track' => $abstractsByTrack,
            'sessions' => $sessions,
            'summary' => [
                'total_accepted' => $acceptedAbstracts->count(),
                'oral_count' => $acceptedAbstracts->where('status', EventAbstract::STATUS_ACCEPTED_ORAL)->count(),
                'poster_count' => $acceptedAbstracts->where('status', EventAbstract::STATUS_ACCEPTED_POSTER)->count(),
                'tracks_count' => $abstractsByTrack->count(),
                'sessions_count' => $sessions->count(),
            ],
        ];
    }

    public function generatePdf(Event $event): DomPDF
    {
        $data = $this->getBookData($event);

        return Pdf::loadView('pdf.abstract-book', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isRemoteEnabled', true);
    }
}
