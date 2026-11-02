<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Event;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * What a chat app or social site shows when an event link is shared: the
 * event's own name, when and where, and its hero image. A private event shares
 * only a name and an invitation, matching what its public page reveals.
 */
final class EventSocialCard
{
    /**
     * @return array{title: string, description: string, site_name: string, type: string, image?: string, image_alt?: string}
     */
    public function for(Event $event, Tenant $tenant): array
    {
        $card = [
            'title' => $event->name,
            'site_name' => $tenant->name,
            'type' => 'website',
        ];

        if ($event->isPrivate()) {
            $card['description'] = 'You are invited. Open the link to register.';

            return $card;
        }

        $card['description'] = $this->description($event);

        $image = $this->absoluteUrl($event->heroImageUrl());
        if ($image !== null) {
            $card['image'] = $image;
            $card['image_alt'] = $event->name;
        }

        return $card;
    }

    private function description(Event $event): string
    {
        $when = $event->starts_at
            ->copy()
            ->setTimezone($event->timezone ?: config('app.timezone'))
            ->format('D j M Y, g:i A T');

        $facts = array_filter([$when, $event->address]);
        $about = Str::limit(mb_trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $event->description))), 160);

        return mb_trim(implode(' · ', $facts).($about !== '' ? ". {$about}" : ''));
    }

    private function absoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        return str_starts_with($url, 'http') ? $url : url($url);
    }
}
