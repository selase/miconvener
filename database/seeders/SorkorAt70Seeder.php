<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The family's celebration of Christiana Korkor Tawiah's seventieth birthday:
 * a free event people must book, with a wall for goodwill messages.
 *
 * The date, time and place are provisional. Re-running updates the event in
 * place, so changing them here and seeding again is the quick way to correct
 * them before the organiser edits the event in the console.
 */
final class SorkorAt70Seeder extends Seeder
{
    public const string TENANT_SLUG = 'purpledot';

    public const string EVENT_SLUG = 'sorkor-at-70';

    public const string TIMEZONE = 'America/New_York';

    public const string STORY_URL = 'https://sorkor-at-70-production-pzgkap.laravel.cloud';

    public function run(): void
    {
        $tenant = Tenant::query()->where('slug', self::TENANT_SLUG)->first();

        if (! $tenant instanceof Tenant) {
            throw new RuntimeException('The '.self::TENANT_SLUG.' tenant does not exist here, so there is nowhere to put the event.');
        }

        $heroPath = 'events/hero/'.self::EVENT_SLUG.'.jpg';
        Storage::disk(Event::uploadDisk())->put($heroPath, (string) file_get_contents(database_path('seeders/assets/sorkor/hero.jpg')));

        $event = Event::query()->firstOrNew(['tenant_id' => $tenant->id, 'slug' => self::EVENT_SLUG]);

        // Only a new event gets a status: re-seeding must not unpublish one the organiser has published.
        if (! $event->exists) {
            $event->status = app()->isProduction() ? Event::STATUS_DRAFT : Event::STATUS_PUBLISHED;
        }

        $event->fill([
            'name' => 'Sorkor at 70: A Celebration of Christiana Korkor Tawiah',
            'description' => $this->description(),
            'event_category' => Event::CATEGORY_CELEBRATION,
            'visibility' => Event::VISIBILITY_PUBLIC,
            'starts_at' => Carbon::parse('2026-11-21 15:00', self::TIMEZONE)->utc(),
            'ends_at' => Carbon::parse('2026-11-21 19:00', self::TIMEZONE)->utc(),
            'timezone' => self::TIMEZONE,
            'location_type' => Event::LOCATION_IN_PERSON,
            'address' => 'Gaithersburg, Maryland, USA (exact venue to be confirmed)',
            'currency' => 'USD',
            'ticket_price' => 0,
            'capacity' => 30,
            'allow_contributions' => false,
            'allow_goodwill_messages' => true,
            'show_tribute_wall' => true,
            'hero_image_path' => $heroPath,
            'cover_image_path' => $heroPath,
        ])->save();

        EventTicketType::query()->updateOrCreate(
            ['event_id' => $event->id, 'name' => 'Guest place'],
            [
                'tenant_id' => $tenant->id,
                'description' => 'Free. Please book so that we can plan seating and food for everyone.',
                'price' => 0,
                'capacity' => 30,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }

    private function description(): string
    {
        $story = self::STORY_URL;

        return <<<TEXT
            Join us as we celebrate seventy years of the life of Christiana Korkor Tawiah, known to everyone who loves her as Sorkor, Kwanye and Verna.

            Born on 24 October 1956 and raised in James Town, Accra, Sorkor has lived a life of faith, enterprise, courage and service: a seamstress and entrepreneur, a deacon, a voice for her community, a wife, a mother and a grandmother. Seven decades on, her family and friends are gathering to say thank you.

            Saturday 21 November 2026, from 3:00 p.m., in Gaithersburg, Maryland. Entry is free, but places are limited to 30, so please book yours.

            Cannot be with us, or have something you want her to hear? Leave a goodwill message on the wall. Every message is read by the family and shared once approved.

            Read her full story: {$story}

            The date, time and venue are still being finalised. Everyone who books will be told straight away if anything changes.
            TEXT;
    }
}
