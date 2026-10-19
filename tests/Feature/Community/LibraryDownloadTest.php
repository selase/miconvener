<?php

declare(strict_types=1);

use App\Models\CommunityLibraryVersion;
use App\Services\Events\PlatformAttendeeVerification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

it('streams only the current private edition after rechecking current access', function () {
    [$tenant, $member, $item] = libraryReader();
    Storage::fake('community-library-local');
    $version = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    Storage::disk($version->disk)->put($version->path, '%PDF-1.4 original');
    $item->update(['current_version_id' => $version->id, 'title' => '../Constitution']);
    $proof = [PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]];
    $url = route('attendee.my.library.file', ['tenantSlug' => $tenant->slug, 'item' => $item->id]);
    $this->withSession($proof)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
    $item->update(['state' => 'withdrawn']);
    $this->get($url)->assertNotFound();
});

it('does not return data to expired proof or a foreign verified member', function () {
    [$tenant, $member, $item] = libraryReader();
    $url = route('attendee.my.library.show', ['tenantSlug' => $tenant->slug, 'item' => $item->id]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->subMinute()->timestamp]])->getJson($url)->assertUnauthorized();
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => 'stranger@example.test', 'expires_at' => now()->addHour()->timestamp]])->getJson($url)->assertNotFound();
});

it('returns a controlled not-found for a missing stored file', function () {
    [$tenant, $member, $item] = libraryReader();
    Storage::fake('community-library-local');
    $version = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    $item->update(['current_version_id' => $version->id]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->get(route('attendee.my.library.file', ['tenantSlug' => $tenant->slug, 'item' => $item->id]))->assertNotFound();
});

it('never serves an old edition when the current pointer changes during lookup', function () {
    [$tenant, $member, $item] = libraryReader();
    Storage::fake('community-library-local');
    $old = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    $new = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    Storage::disk($old->disk)->put($old->path, 'old edition');
    Storage::disk($new->disk)->put($new->path, 'new edition');
    $item->update(['current_version_id' => $old->id]);
    $changed = false;
    App\Models\CommunityLibraryItem::retrieved(function ($record) use ($item, $new, &$changed): void {
        if (! $changed && $record->id === $item->id) {
            $changed = true;
            App\Models\CommunityLibraryItem::withoutGlobalScopes()->whereKey($item->id)->update(['current_version_id' => $new->id]);
        }
    });
    $response = $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->get(route('attendee.my.library.file', ['tenantSlug' => $tenant->slug, 'item' => $item->id]))->assertOk();
    expect($response->streamedContent())->toBe('new edition');
});

it('lists only released documents belonging to the current member audience', function () {
    [$tenant, $member, $item] = libraryReader();
    App\Models\CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'state' => 'draft']);
    App\Models\CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'audience' => ['kind' => 'all_members', 'unsupported' => true]]);
    App\Models\CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id, 'audience' => ['kind' => 'groups', 'group_ids' => []]]);
    $this->withSession([PlatformAttendeeVerification::SESSION_KEY => ['email' => $member->email, 'expires_at' => now()->addHour()->timestamp]])
        ->getJson(route('attendee.my.library.index', ['tenantSlug' => $tenant->slug]).'?search=')->assertOk()->assertJsonCount(1, 'entries.data')->assertJsonPath('entries.data.0.id', $item->id);
});
