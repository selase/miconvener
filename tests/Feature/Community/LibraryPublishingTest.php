<?php

declare(strict_types=1);

use App\Models\CommunityLibraryItem;
use App\Models\CommunityLibraryVersion;
use App\Services\Community\CommunityContentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

it('publishes a private version without disclosing its storage key', function () {
    [$tenant, $user] = libraryStaff();
    Storage::fake('community-library-local');
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $response = $this->post(route('tenant.community.library.store', ['subdomain' => $tenant->slug]), [
        'title' => 'Constitution', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'edition' => '2026',
        'file' => UploadedFile::fake()->createWithContent('constitution.pdf', "%PDF-1.4\nChurch constitution"),
    ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('item.kind', 'document');
    $version = CommunityLibraryVersion::query()->firstOrFail();
    expect($version->disk)->toBe('community-library-local')->and($response->json('item'))->not->toHaveKeys(['path', 'disk', 'checksum']);
    Storage::disk($version->disk)->assertExists($version->path);
    expect($version->path)->toStartWith('community-library/'.$tenant->id.'/');
});

it('rejects unsupported files and oversized uploads', function (string $filename, string $mime, int $size) {
    [$tenant, $user] = libraryStaff();
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->post(route('tenant.community.library.store', ['subdomain' => $tenant->slug]), ['title' => 'Unsafe', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'file' => UploadedFile::fake()->create($filename, $size, $mime)], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    expect(CommunityLibraryItem::query()->count())->toBe(0);
})->with([['page.html', 'text/html', 1], ['large.pdf', 'application/pdf', 20481]]);

it('preserves immutable old editions and blocks publishing after a downgrade but permits withdrawal', function () {
    [$tenant, $user] = libraryStaff();
    Storage::fake('community-library-local');
    $item = CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id]);
    $old = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    $item->update(['current_version_id' => $old->id]);
    $url = route('tenant.community.library.update', ['subdomain' => $tenant->slug, 'item' => $item->id]);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->post($url, ['title' => 'Revised constitution', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'edition' => 'Revised2026', 'file' => UploadedFile::fake()->createWithContent('revised.pdf', "%PDF-1.4\nRevision")], ['Accept' => 'application/json'])->assertOk();
    expect($item->fresh()->current_version_id)->not->toBe($old->id)->and($item->versions()->count())->toBe(2);
    expect(fn () => $old->update(['edition' => 'silently edited']))->toThrow(DomainException::class);
    $tenant->features()->where('feature_key', 'community_library_access')->update(['enabled' => false]);
    $this->postJson($url, ['title' => 'Unapproved', 'state' => 'published', 'audience' => ['kind' => 'all_members']])->assertForbidden();
    $this->postJson(route('tenant.community.library.withdraw', ['subdomain' => $tenant->slug, 'item' => $item->id]))->assertOk();
    expect($item->fresh()->state)->toBe('withdrawn')->and($item->versions()->count())->toBe(2);
});

it('honours an explicitly configured storage byte quota', function () {
    [$tenant] = libraryStaff();
    $tenant->features()->create(['feature_key' => 'storage_bytes', 'enabled' => true, 'meta' => ['type' => 'limit', 'value' => 1]]);
    Storage::fake('community-library-local');
    expect(fn () => app(CommunityContentStorage::class)->put($tenant, UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\nNotes")))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    Storage::disk('community-library-local')->assertDirectoryEmpty('community-library/'.$tenant->id);
});

it('keeps staff history tenant-bound and returns only safe version links', function () {
    [$tenant, $user] = libraryStaff();
    $item = CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id]);
    $version = CommunityLibraryVersion::factory()->create(['tenant_id' => $tenant->id, 'item_id' => $item->id]);
    Storage::fake('community-library-local');
    Storage::disk($version->disk)->put($version->path, '%PDF-1.4');
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    $this->get(route('tenant.community.library.index', ['subdomain' => $tenant->slug]))->assertOk();
    $this->getJson(route('tenant.community.library.history', ['subdomain' => $tenant->slug, 'item' => $item->id]))
        ->assertOk()->assertJsonPath('versions.data.0.edition', $version->edition);
    $this->get(route('tenant.community.library.version', ['subdomain' => $tenant->slug, 'item' => $item->id, 'version' => $version->id]))->assertOk();
    $foreign = CommunityLibraryItem::factory()->create();
    $this->postJson(route('tenant.community.library.withdraw', ['subdomain' => $tenant->slug, 'item' => $foreign->id]))->assertNotFound();
});

it('refuses library management without its dedicated permission', function () {
    [$tenant] = libraryStaff();
    $user = App\Models\User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($user->id);
    $this->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)])
        ->get(route('tenant.community.library.index', ['subdomain' => $tenant->slug]))->assertForbidden();
});

it('deletes only its new file if saving the version fails', function () {
    [$tenant, $user] = libraryStaff();
    Storage::fake('community-library-local');
    $fail = true;
    CommunityLibraryVersion::creating(function () use (&$fail): void {
        if ($fail) {
            $fail = false;
            throw new RuntimeException('Simulated database failure');
        }
    });
    $this->withoutExceptionHandling()->actingAs($user)->withServerVariables(['HTTP_HOST' => eventSubdomainHost($tenant->slug)]);
    expect(fn () => $this->post(route('tenant.community.library.store', ['subdomain' => $tenant->slug]), [
        'title' => 'Constitution', 'state' => 'published', 'audience' => ['kind' => 'all_members'],
        'file' => UploadedFile::fake()->createWithContent('constitution.pdf', "%PDF-1.4\nNotes"),
    ], ['Accept' => 'application/json']))->toThrow(RuntimeException::class, 'Simulated database failure');
    expect(CommunityLibraryItem::query()->count())->toBe(0)->and(Storage::disk('community-library-local')->allFiles())->toBe([]);
});
