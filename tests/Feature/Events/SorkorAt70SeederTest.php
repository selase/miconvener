<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventTicketType;
use App\Models\Tenant;
use Database\Seeders\SorkorAt70Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Storage::fake(Event::uploadDisk());
});

test('the celebration is a free, 30 place event with a goodwill wall', function (): void {
    $tenant = Tenant::factory()->create(['slug' => SorkorAt70Seeder::TENANT_SLUG, 'isolation_mode' => 'shared']);

    $this->seed(SorkorAt70Seeder::class);

    $event = Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->sole();

    expect($event->tenant_id)->toBe($tenant->id)
        ->and($event->isCelebration())->toBeTrue()
        ->and($event->isFree())->toBeTrue()
        ->and($event->capacity)->toBe(30)
        ->and($event->allowsGoodwillMessages())->toBeTrue()
        ->and($event->allowsContributions())->toBeFalse()
        ->and($event->showsCommunityPrompts())->toBeFalse()
        ->and($event->timezone)->toBe('America/New_York')
        ->and($event->starts_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-28 15:00')
        ->and($event->description)->toContain(SorkorAt70Seeder::STORY_URL);

    Storage::disk(Event::uploadDisk())->assertExists($event->hero_image_path);
    expect(EventTicketType::query()->where('event_id', $event->id)->sole()->capacity)->toBe(30);
});

test('seeding twice updates the event instead of duplicating it', function (): void {
    Tenant::factory()->create(['slug' => SorkorAt70Seeder::TENANT_SLUG, 'isolation_mode' => 'shared']);

    $this->seed(SorkorAt70Seeder::class);
    $this->seed(SorkorAt70Seeder::class);

    expect(Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->count())->toBe(1)
        ->and(EventTicketType::query()->count())->toBe(1);
});

test('seeding again keeps an event the organiser has published', function (): void {
    Tenant::factory()->create(['slug' => SorkorAt70Seeder::TENANT_SLUG, 'isolation_mode' => 'shared']);

    $this->seed(SorkorAt70Seeder::class);
    Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->update(['status' => Event::STATUS_PUBLISHED]);
    $this->seed(SorkorAt70Seeder::class);

    expect(Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->sole()->status)->toBe(Event::STATUS_PUBLISHED);
});

test('seeding without the tenant explains what is missing', function (): void {
    expect(fn () => $this->seed(SorkorAt70Seeder::class))->toThrow(RuntimeException::class, 'purpledot');
});

test('seeding again keeps a membership option the organiser switched back on', function (): void {
    Tenant::factory()->create(['slug' => SorkorAt70Seeder::TENANT_SLUG, 'isolation_mode' => 'shared']);
    $this->seed(SorkorAt70Seeder::class);
    Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->update(['show_community_prompts' => true]);

    $this->seed(SorkorAt70Seeder::class);

    expect(Event::query()->where('slug', SorkorAt70Seeder::EVENT_SLUG)->sole()->showsCommunityPrompts())->toBeTrue();
});
