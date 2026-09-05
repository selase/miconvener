<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventForumBan;
use App\Models\EventForumThread;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

function forumHost(): array
{
    $tenant = Tenant::factory()->create(['slug' => 'acme', 'isolation_mode' => 'shared']);
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $user->assignRole('Org Superadmin');
    $tenant->users()->attach($user->id);

    return [$tenant, $user];
}

test('a visitor can post a question from the public event page', function () {
    [$tenant] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->post("http://{$host}/e/{$event->slug}/forum", [
        'title' => 'Will the sandbox stay open?',
        'body' => 'Asking for a colleague.',
        'author_name' => 'Kwame Asante',
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    expect(EventForumThread::where('event_id', $event->id)->where('title', 'Will the sandbox stay open?')->exists())->toBeTrue();
});

test('a visitor can attach a file to their question', function () {
    [$tenant] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    Storage::fake('public');

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->post("http://{$host}/e/{$event->slug}/forum", [
        'title' => 'Can you share the slide deck?',
        'body' => 'See attached for what I mean.',
        'author_name' => 'Kwame Asante',
        'attachment' => UploadedFile::fake()->create('question.pdf', 100),
    ], ['HTTP_HOST' => $host]);

    $response->assertRedirect();
    $thread = EventForumThread::where('event_id', $event->id)->where('title', 'Can you share the slide deck?')->firstOrFail();
    expect($thread->attachment_path)->not->toBeNull();
    expect($thread->attachment_name)->toBe('question.pdf');
    Storage::disk('public')->assertExists($thread->attachment_path);
});

test('hidden threads do not appear in the public listing', function () {
    [$tenant] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Visible thread', 'is_hidden' => false]);
    EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'title' => 'Hidden thread', 'is_hidden' => true]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->getJson("http://{$host}/e/{$event->slug}/forum", ['HTTP_HOST' => $host]);

    $titles = collect($response->json())->pluck('title');
    expect($titles)->toContain('Visible thread');
    expect($titles)->not->toContain('Hidden thread');
});

test('host can reply with a tag, and an official_answer tag marks the thread answered', function () {
    [$tenant, $user] = forumHost();
    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $thread = EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $response = $this->actingAs($user)->postJson("http://{$host}/events/{$event->id}/forum/{$thread->id}/replies", [
        'body' => 'The sandbox opens 25 September.',
        'tag' => 'official_answer',
    ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($thread->fresh()->is_answered)->toBeTrue();
    expect($response->json('replies.0.is_from_host'))->toBeTrue();
});

test('a host without update event permission cannot moderate a thread', function () {
    [$tenant] = forumHost();
    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    setPermissionsTeamId($tenant->id);
    $tenant->users()->attach($user->id); // no role assigned

    $event = Event::factory()->create(['tenant_id' => $tenant->id]);
    $thread = EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/forum/{$thread->id}", [
        'is_pinned' => true,
    ], ['HTTP_HOST' => $host])->assertForbidden();
});

test('an anonymous question shows as Anonymous publicly but the real name to the host', function () {
    [$tenant, $user] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->post("http://{$host}/e/{$event->slug}/forum", [
        'title' => 'A private worry',
        'body' => 'Does this get recorded?',
        'author_name' => 'Efua Nyarko',
        'is_anonymous' => true,
    ], ['HTTP_HOST' => $host])->assertRedirect();

    $publicListing = $this->getJson("http://{$host}/e/{$event->slug}/forum", ['HTTP_HOST' => $host]);
    expect($publicListing->json('0.author_name'))->toBe('Anonymous');

    $hostListing = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/forum", ['HTTP_HOST' => $host]);
    expect($hostListing->json('threads.0.author_name'))->toBe('Efua Nyarko');
    expect($hostListing->json('threads.0.is_anonymous'))->toBeTrue();
});

test('a visitor can upvote a thread once and unvote it', function () {
    [$tenant] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $thread = EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";
    $url = "http://{$host}/e/{$event->slug}/forum/{$thread->id}/vote";

    $this->postJson($url, ['respondent_token' => 'voter-1'], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('votes_count', 1);

    $this->postJson($url, ['respondent_token' => 'voter-1'], ['HTTP_HOST' => $host])
        ->assertStatus(422);

    $this->deleteJson($url, ['respondent_token' => 'voter-1'], ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('votes_count', 0);
});

test('a report is deduped per reporter and does not appear in the public payload', function () {
    [$tenant, $user] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $thread = EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id]);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";
    $url = "http://{$host}/e/{$event->slug}/forum/{$thread->id}/report";

    $this->postJson($url, ['reporter_token' => 'reporter-1', 'reason' => 'Off topic'], ['HTTP_HOST' => $host])->assertOk();
    $this->postJson($url, ['reporter_token' => 'reporter-1', 'reason' => 'Off topic again'], ['HTTP_HOST' => $host])->assertOk();

    expect($thread->reports()->count())->toBe(1);

    $publicListing = $this->getJson("http://{$host}/e/{$event->slug}/forum", ['HTTP_HOST' => $host]);
    expect($publicListing->json('0'))->not->toHaveKey('reports_count');

    $hostListing = $this->actingAs($user)->getJson("http://{$host}/events/{$event->id}/forum", ['HTTP_HOST' => $host]);
    expect($hostListing->json('threads.0.reports_count'))->toBe(1);
});

test('a host can ban a threads author by email, which hides the thread and blocks future posts', function () {
    [$tenant, $user] = forumHost();
    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);
    $thread = EventForumThread::factory()->create(['tenant_id' => $tenant->id, 'event_id' => $event->id, 'author_email' => 'spammer@example.com']);

    $baseDomain = mb_ltrim((string) config('session.domain'), '.');
    $host = "acme.{$baseDomain}";

    $this->actingAs($user)->patchJson("http://{$host}/events/{$event->id}/forum/{$thread->id}/ban", [
        'reason' => 'Repeated spam',
    ], ['HTTP_HOST' => $host])->assertOk();

    expect($thread->fresh()->is_hidden)->toBeTrue();
    expect(EventForumBan::where('event_id', $event->id)->where('author_email', 'spammer@example.com')->exists())->toBeTrue();

    $blocked = $this->post("http://{$host}/e/{$event->slug}/forum", [
        'title' => 'Trying again',
        'body' => 'Let me back in',
        'author_name' => 'Spammer',
        'author_email' => 'spammer@example.com',
    ], ['HTTP_HOST' => $host]);
    $blocked->assertRedirect();
    expect(EventForumThread::where('title', 'Trying again')->exists())->toBeFalse();

    $ban = EventForumBan::where('event_id', $event->id)->where('author_email', 'spammer@example.com')->firstOrFail();
    $this->actingAs($user)->deleteJson("http://{$host}/events/{$event->id}/forum/bans/{$ban->id}", [], ['HTTP_HOST' => $host])
        ->assertOk();
    expect(EventForumBan::where('id', $ban->id)->exists())->toBeFalse();

    $this->post("http://{$host}/e/{$event->slug}/forum", [
        'title' => 'Trying again',
        'body' => 'Let me back in',
        'author_name' => 'Spammer',
        'author_email' => 'spammer@example.com',
    ], ['HTTP_HOST' => $host])->assertRedirect();
    expect(EventForumThread::where('title', 'Trying again')->exists())->toBeTrue();
});
