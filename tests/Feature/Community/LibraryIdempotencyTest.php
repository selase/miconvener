<?php

declare(strict_types=1);

use App\Models\CommunityLibraryItem;
use App\Models\CommunityLibraryVersion;
use App\Services\Community\CommunityLibrary;
use App\Services\Tenancy\StorageMeter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../../Support/CommunityLibraryScenario.php';

it('replays a lost successful upload response without a second item file or storage charge', function () {
    [$tenant,$actor] = libraryStaff();
    Storage::fake('community-library-local');
    $data = ['title' => 'Constitution', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'edition' => '2026', 'write_key' => (string) Str::uuid()];
    $file = fn () => UploadedFile::fake()->createWithContent('constitution.pdf', "%PDF-1.4\nExact bytes");
    $engine = app(CommunityLibrary::class);
    $first = $engine->save($tenant, $data, $file(), null, (int) $actor->id);
    $bytes = app(StorageMeter::class)->calculateUsage($tenant);
    $second = $engine->save($tenant, $data, $file(), null, (int) $actor->id);
    expect($second->id)->toBe($first->id)->and(CommunityLibraryItem::query()->count())->toBe(1)->and(CommunityLibraryVersion::query()->count())->toBe(1)->and(count(Storage::disk('community-library-local')->allFiles()))->toBe(1)->and(app(StorageMeter::class)->calculateUsage($tenant))->toBe($bytes);
});

it('does not duplicate editions or revert newer edits when an earlier write is retried', function () {
    [$tenant,$actor] = libraryStaff();
    Storage::fake('community-library-local');
    $engine = app(CommunityLibrary::class);
    $item = CommunityLibraryItem::factory()->create(['tenant_id' => $tenant->id]);
    $data = ['title' => 'Revised', 'plain_text' => 'Notes', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'write_key' => (string) Str::uuid()];
    $file = fn () => UploadedFile::fake()->createWithContent('notes.pdf', "%PDF-1.4\nRevision");
    $engine->save($tenant, $data, $file(), $item, (int) $actor->id);
    $engine->save($tenant, [...$data, 'title' => 'Latest title', 'write_key' => (string) Str::uuid()], null, $item, (int) $actor->id);
    $engine->save($tenant, $data, $file(), $item, (int) $actor->id);
    expect($item->fresh()->title)->toBe('Latest title')->and($item->versions()->count())->toBe(1);
});

it('rejects reuse of a write key with changed content or a different item', function () {
    [$tenant,$actor] = libraryStaff();
    $engine = app(CommunityLibrary::class);
    $data = ['title' => 'First', 'plain_text' => 'Notes', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'write_key' => (string) Str::uuid()];
    $item = $engine->save($tenant, $data, null, null, (int) $actor->id);
    expect(fn () => $engine->save($tenant, [...$data, 'title' => 'Changed'], null, null, (int) $actor->id))->toThrow(HttpException::class);
    expect(fn () => $engine->save($tenant, $data, null, $item, (int) $actor->id))->toThrow(HttpException::class);
    expect($item->fresh()->title)->toBe('First');
});

it('retains a committed edition when the transaction acknowledgement is lost', function () {
    [$tenant,$actor] = libraryStaff();
    Storage::fake('community-library-local');
    $connection = Illuminate\Support\Facades\DB::connection('landlord');
    $wrapper = Mockery::mock(Illuminate\Database\Connection::class);
    $wrapper->shouldReceive('transaction')->once()->andReturnUsing(function ($callback) use ($connection) {
        $connection->transaction($callback);
        throw new RuntimeException('Commit acknowledgement lost');
    });
    Illuminate\Support\Facades\DB::shouldReceive('connection')->with('landlord')->once()->andReturn($wrapper);
    $data = ['title' => 'Constitution', 'state' => 'published', 'audience' => ['kind' => 'all_members'], 'write_key' => (string) Str::uuid()];
    $file = fn () => UploadedFile::fake()->createWithContent('constitution.pdf', "%PDF-1.4\nCommitted bytes");
    expect(fn () => app(CommunityLibrary::class)->save($tenant, $data, $file(), null, (int) $actor->id))->toThrow(RuntimeException::class, 'Commit acknowledgement lost');
    Illuminate\Support\Facades\DB::shouldReceive('connection')->with('landlord')->andReturn($connection);
    $retried = app(CommunityLibrary::class)->save($tenant, $data, $file(), null, (int) $actor->id);
    expect($retried->versions()->count())->toBe(1);
    expect(Storage::disk('community-library-local')->get($retried->currentVersion->path))->toBe("%PDF-1.4\nCommitted bytes");
});
