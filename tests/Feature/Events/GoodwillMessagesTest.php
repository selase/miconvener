<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventContribution;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Events\EventLexicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
    Mail::fake();
    Queue::fake();

    $this->tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $this->host = 'acme.'.mb_ltrim((string) config('session.domain'), '.');
    $this->event = Event::factory()->published()->create([
        'tenant_id' => $this->tenant->id,
        'event_category' => Event::CATEGORY_CELEBRATION,
        'allow_goodwill_messages' => true,
        'allow_contributions' => false,
    ]);
});

function leaveGoodwillMessage(array $overrides = []): TestResponse
{
    return test()->post(
        'http://'.test()->host.'/e/'.test()->event->slug.'/messages',
        array_merge([
            'contributor_name' => 'Ama Osei',
            'tribute_message' => 'Happy 70th, Auntie. God keep you.',
        ], $overrides),
        ['HTTP_HOST' => test()->host],
    );
}

test('a guest can leave a goodwill message without giving anything', function (): void {
    leaveGoodwillMessage()->assertRedirect()->assertSessionHas('success');

    $message = EventContribution::query()->where('event_id', $this->event->id)->sole();

    expect($message->status)->toBe(EventContribution::STATUS_MESSAGE)
        ->and($message->amount)->toBe(0)
        ->and($message->is_approved)->toBeFalse()
        ->and($message->tribute_message)->toBe('Happy 70th, Auntie. God keep you.')
        ->and($message->contributor_name)->toBe('Ama Osei');
});

test('a message stays off the wall until the organiser approves it', function (): void {
    leaveGoodwillMessage();
    $message = EventContribution::query()->sole();

    $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn (Assert $page) => $page->where('event.tributes', []));

    $message->update(['is_approved' => true]);

    $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.allow_goodwill_messages', true)
            ->has('event.tributes', 1)
            ->where('event.tributes.0.tribute_message', 'Happy 70th, Auntie. God keep you.')
            ->where('event.tributes.0.amount', null));
});

test('a message is refused when the event does not take them', function (): void {
    $this->event->update(['allow_goodwill_messages' => false]);

    leaveGoodwillMessage()->assertRedirect()->assertSessionHas('error');

    expect(EventContribution::query()->count())->toBe(0);
});

test('a message needs a name and some words', function (): void {
    leaveGoodwillMessage(['contributor_name' => '', 'tribute_message' => ''])
        ->assertSessionHasErrors(['contributor_name', 'tribute_message']);

    leaveGoodwillMessage(['tribute_message' => str_repeat('a', 1001)])
        ->assertSessionHasErrors('tribute_message');

    expect(EventContribution::query()->count())->toBe(0);
});

test('a bot that fills the hidden field is told thanks and nothing is saved', function (): void {
    leaveGoodwillMessage(['website' => 'https://spam.example'])->assertRedirect()->assertSessionHas('success');

    expect(EventContribution::query()->count())->toBe(0);
});

test('a guest may hide their name on the wall', function (): void {
    leaveGoodwillMessage(['is_anonymous' => true]);
    EventContribution::query()->sole()->update(['is_approved' => true]);

    $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn (Assert $page) => $page->where('event.tributes.0.contributor_name', 'Anonymous'));
});

test('messages never count as money raised', function (): void {
    $this->event->update(['allow_contributions' => true]);
    leaveGoodwillMessage();
    EventContribution::query()->sole()->update(['is_approved' => true]);

    $this->get("http://{$this->host}/e/{$this->event->slug}", ['HTTP_HOST' => $this->host])
        ->assertInertia(fn (Assert $page) => $page
            ->where('event.contributions_total_pesewas', 0)
            ->where('event.contributions_count', 0)
            ->has('event.tributes', 1));
});

test('the organiser can switch goodwill messages on and approve one', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    setPermissionsTeamId($this->tenant->id);
    $user->assignRole('Org Superadmin');
    $this->tenant->users()->attach($user->id);
    $this->event->update(['allow_goodwill_messages' => false]);

    $this->actingAs($user)->patch("http://{$this->host}/events/{$this->event->slug}/contributions/settings", [
        'allow_contributions' => false,
        'allow_goodwill_messages' => true,
        'show_tribute_wall' => true,
        'show_contributor_amounts' => false,
    ], ['HTTP_HOST' => $this->host])->assertRedirect();

    expect($this->event->fresh()->allow_goodwill_messages)->toBeTrue();

    leaveGoodwillMessage();
    $message = EventContribution::query()->sole();

    $this->actingAs($user)->patch(
        "http://{$this->host}/events/{$this->event->slug}/contributions/{$message->id}/toggle-approval",
        [],
        ['HTTP_HOST' => $this->host],
    )->assertRedirect();

    expect($message->fresh()->is_approved)->toBeTrue();
});

test('the celebration wording speaks of goodwill, never condolence', function (): void {
    $lexicon = EventLexicon::forCategory(Event::CATEGORY_CELEBRATION);
    $words = mb_strtolower(implode(' ', array_filter($lexicon, 'is_string')));

    expect($lexicon['wall_title'])->toBe('Wall of Goodwill')
        ->and($words)->not->toContain('tribute')
        ->not->toContain('condolence')
        ->not->toContain('funeral')
        ->not->toContain('burial');
});
