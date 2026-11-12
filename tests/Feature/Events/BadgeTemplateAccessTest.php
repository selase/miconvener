<?php

declare(strict_types=1);

use App\Models\BadgeDesignProvenance;
use App\Models\BadgeTemplatePurchase;
use App\Models\BadgeTemplateUse;
use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Badges\BadgeTemplateAccess;
use App\Services\Badges\BadgeTemplateService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    refreshTenantDatabases();
    $this->purchase = BadgeTemplatePurchase::factory()->create(['state' => 'paid']);
    $this->event = $this->purchase->event;
    $this->template = app(BadgeTemplateService::class)->forEvent($this->event);
    $this->tenant = Tenant::query()->whereKey($this->event->tenant_id)->firstOrFail();
    $this->actor = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->tenant->users()->attach($this->actor->id);
    setPermissionsTeamId($this->tenant->id);
    foreach (['read badge-template', 'update badge-template', 'update event'] as $permission) {
        App\Models\Permission::query()->firstOrCreate(['name' => $permission, 'guard_name' => 'web'], ['category' => 'event']);
        $this->actor->givePermissionTo($permission);
    }
    $this->purchase->licence->update(['state' => 'active', 'current_purchase_id' => $this->purchase->id]);
    BadgeDesignProvenance::query()->create(['source_type' => 'event', 'source_id' => $this->event->id, 'family_id' => $this->purchase->family_id]);
});

test('clean output records first use once and repeat downloads retain the event licence', function (): void {
    $key = (string) Str::uuid();
    $access = app(BadgeTemplateAccess::class);
    $access->claimUse($this->event, $this->actor, [], 'badge_pdf', $key);
    $record = BadgeTemplateUse::query()->firstOrFail();
    expect((string) $record->actor_id)->toBe((string) $this->actor->id)->and($record->purpose)->toBe('badge_pdf');
    expect($record->snapshot_hash)->toHaveLength(64);
    $access->claimUse($this->event, $this->actor, [], 'badge_pdf', $key);
    expect(BadgeTemplateUse::query()->where('purchase_id', $this->purchase->id)->count())->toBe(1)
        ->and($this->purchase->licence->fresh()->first_used_at)->not->toBeNull();
});

test('a suspended licence cannot emit clean bytes even with a previous output key', function (): void {
    $access = app(BadgeTemplateAccess::class);
    $key = (string) Str::uuid();
    $access->claimUse($this->event, $this->actor, [], 'badge_pdf', $key);
    $this->purchase->licence->update(['state' => 'suspended']);
    expect(fn () => $access->claimUse($this->event, $this->actor, [], 'badge_pdf', $key))->toThrow(ValidationException::class);
});

test('the same design needs a separate licence for another event', function (): void {
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => app(BadgeTemplateAccess::class)->assertLicensed($other, [$this->purchase->family_id]))->toThrow(ValidationException::class);
});

test('free designs retain output without any licence or use record', function (): void {
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    app(BadgeTemplateService::class)->forEvent($other);
    app(BadgeTemplateAccess::class)->claimUse($other, $this->actor, [], 'badge_pdf', (string) Str::uuid());
    expect(BadgeTemplateUse::query()->count())->toBe(0);
});

test('a licensed design can be applied and privately saved while its derivatives remain paid', function (): void {
    $library = app(App\Services\Badges\BadgeLibraryService::class);
    $version = $this->purchase->version->design->versions()->create(['version' => 2, 'snapshot' => $library->snapshot($this->event, $this->template)]);
    $applied = $library->apply($this->event, ['version_id' => $version->id, 'expected_version' => 1]);
    expect($applied->source_version_id)->toBe($version->id)->and(BadgeTemplateUse::query()->count())->toBe(0);
    $copy = $library->save($this->event, ['name' => 'Private derivative', 'scope' => 'organisation', 'expected_version' => 2]);
    expect(app(BadgeTemplateAccess::class)->families('version', $copy->id))->toContain($this->purchase->family_id);
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    expect(fn () => $library->apply($other, ['version_id' => $copy->id, 'expected_version' => 1]))->toThrow(ValidationException::class);
});

test('clearing the editable source id cannot remove the stored paid provenance', function (): void {
    $this->template->update(['source_version_id' => null]);
    $this->purchase->licence->update(['state' => 'suspended']);
    expect(fn () => app(BadgeTemplateAccess::class)->claimUse($this->event, $this->actor, [], 'badge_pdf', (string) Str::uuid()))->toThrow(ValidationException::class);
});

test('a paid family cannot become a free laundering path by changing its price', function (): void {
    expect(fn () => $this->purchase->version->design->update(['price_pesewas' => 0]))->toThrow(Illuminate\Database\QueryException::class);
});

test('a private derivative only exposes a flattened watermarked preview to another event', function (): void {
    $library = app(App\Services\Badges\BadgeLibraryService::class);
    $copy = $library->save($this->event, ['name' => 'Licensed private copy', 'scope' => 'organisation', 'expected_version' => 1]);
    $other = Event::factory()->create(['tenant_id' => $this->tenant->id]);
    $host = eventSubdomainHost($this->tenant->slug);
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])
        ->getJson("http://{$host}/events/{$other->id}/badges/library/versions/{$copy->id}")
        ->assertOk()->assertJsonMissingPath('template')->assertJsonStructure(['preview_image']);
});

test('the PDF service refuses paid output without a bound use claim', function (): void {
    expect(fn () => app(App\Services\Badges\BadgePdfService::class)->generate($this->event, $this->template, collect()))->toThrow(ValidationException::class);
});

test('a bound paid PDF claim is accepted and keeps old artwork available after an edit', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::fake('public');
    $artwork = app(App\Services\Design\ArtifactArtworkService::class)->store(Illuminate\Http\UploadedFile::fake()->image('owned.png', 540, 850), $this->tenant->id, $this->event->id, 'badge-background');
    $this->template->update(['background_disk' => $artwork->disk, 'background_path' => $artwork->path]);
    $claim = app(BadgeTemplateAccess::class)->claimUse($this->event, $this->actor, [], 'badge_pdf', (string) Str::uuid());
    $record = BadgeTemplateUse::query()->whereKey($claim['uses'][0])->firstOrFail();
    expect($record->licence->current_purchase_id)->toBe($this->purchase->id)
        ->and($record->purchase->state)->toBe('paid')
        ->and($record->render_snapshot)->toEqual($claim['template']->only([...App\Services\Badges\BadgeLibraryService::FIELDS, 'design_version']));
    app(BadgeTemplateAccess::class)->assertOutputClaim($this->event, $claim['template'], $claim['uses']);
    $this->template->update(['background_disk' => null, 'background_path' => null]);
    app(App\Services\Design\ArtifactArtworkCleanup::class)->deleteUnreferenced([[$artwork->disk, $artwork->path]]);
    Illuminate\Support\Facades\Storage::disk($artwork->disk)->assertExists($artwork->path);
});

test('clean preview requires a deliberate post and records use without an email', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    $host = eventSubdomainHost($this->tenant->slug);
    $url = "http://{$host}/events/{$this->event->id}/badges/clean-preview";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host])->postJson($url, ['output_key' => (string) Str::uuid()])->assertUnprocessable();
    expect($this->purchase->licence->fresh()->first_used_at)->toBeNull();
    $this->postJson($url, ['confirm_paid_use' => true, 'output_key' => (string) Str::uuid()])->assertOk();
    expect($this->purchase->licence->fresh()->first_used_at)->not->toBeNull();
    Illuminate\Support\Facades\Mail::assertNothingSent();
});

test('paid previews adapt to the requested dimensions and sample name on the server', function (): void {
    $snapshot = app(App\Services\Badges\BadgeLibraryService::class)->snapshot($this->event, $this->template);
    $version = $this->purchase->version->design->versions()->create(['version' => 2, 'snapshot' => $snapshot]);
    $host = eventSubdomainHost($this->tenant->slug);
    $base = "http://{$host}/events/{$this->event->id}/badges/library/versions/{$version->id}";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $first = $this->getJson($base.'?width_mm=54&height_mm=85&sample_name=Audrey%20Barfi')->assertOk()->json('preview_image');
    $second = $this->getJson($base.'?width_mm=54&height_mm=85&sample_name=Another%20Person')->assertOk()->json('preview_image');
    $dimensions = getimagesizefromstring(base64_decode(explode(',', $first, 2)[1], true));
    expect($dimensions[0])->toBe(600)->and($dimensions[1])->toBe(944)->and($first)->not->toBe($second);
});

test('preview inputs cannot request arbitrary fonts or a missing back face', function (): void {
    $snapshot = app(App\Services\Badges\BadgeLibraryService::class)->snapshot($this->event, $this->template);
    $version = $this->purchase->version->design->versions()->create(['version' => 2, 'snapshot' => $snapshot]);
    $host = eventSubdomainHost($this->tenant->slug);
    $base = "http://{$host}/events/{$this->event->id}/badges/library/versions/{$version->id}";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $this->getJson($base.'?font_family=https://example.test/evil.ttf')->assertUnprocessable();
    $this->getJson($base.'?side=back')->assertUnprocessable();
});

test('a use claim cannot authorise a changed render snapshot', function (): void {
    $claim = app(BadgeTemplateAccess::class)->claimUse($this->event, $this->actor, [], 'badge_pdf', (string) Str::uuid());
    $changed = clone $claim['template'];
    $changed->width_mm = 101;
    expect(fn () => app(BadgeTemplateAccess::class)->assertOutputClaim($this->event, $changed, $claim['uses']))->toThrow(ValidationException::class);
});

test('an already published free family retains its free entitlement', function (): void {
    $family = App\Models\BadgeLibraryDesign::query()->create(['scope' => 'platform', 'name' => 'Always free', 'price_pesewas' => 0]);
    $family->versions()->create(['version' => 1, 'snapshot' => []]);
    expect(fn () => $family->update(['price_pesewas' => 5000]))->toThrow(Illuminate\Database\QueryException::class);
});

test('paid PDF confirmation and print history share the generated output identity', function (): void {
    Illuminate\Support\Facades\Mail::fake();
    $registration = App\Models\EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'full_name' => 'Sample Attendee']);
    $host = eventSubdomainHost($this->tenant->slug);
    $url = "http://{$host}/events/{$this->event->id}/badges/sheet";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $this->postJson($url, ['registration_ids' => [$registration->id]])->assertUnprocessable();
    $this->postJson($url, ['registration_ids' => [$registration->id], 'confirm_paid_use' => true])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $use = BadgeTemplateUse::query()->where('purpose', 'badge_pdf')->firstOrFail();
    expect(App\Models\EventBadgePrint::query()->where('registration_id', $registration->id)->firstOrFail()->export_reference)->toBe($use->output_key);
});

test('a paid back-face preview supports custom landscape dimensions without recording use', function (): void {
    $snapshot = app(App\Services\Badges\BadgeLibraryService::class)->snapshot($this->event, $this->template);
    $snapshot['back'] = ['layout' => ['attendee_name' => $snapshot['layout']['attendee_name']], 'background_disk' => null, 'background_path' => null, 'background_settings' => ['fit' => 'contain', 'position' => 'center']];
    $version = $this->purchase->version->design->versions()->create(['version' => 2, 'snapshot' => $snapshot]);
    $host = eventSubdomainHost($this->tenant->slug);
    $url = "http://{$host}/events/{$this->event->id}/badges/library/versions/{$version->id}?width_mm=85&height_mm=54";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $front = $this->getJson($url)->assertOk()->json('preview_image');
    $back = $this->getJson($url.'&side=back')->assertOk()->json('preview_image');
    $dimensions = getimagesizefromstring(base64_decode(explode(',', $back, 2)[1], true));
    expect($dimensions[0])->toBe(600)->and($dimensions[1])->toBe(381)->and($front)->not->toBe($back)
        ->and($this->purchase->licence->fresh()->first_used_at)->toBeNull();
});

test('current front back and logo assets stay watermarked until deliberate use and after suspension', function (): void {
    Illuminate\Support\Facades\Storage::fake('local');
    Illuminate\Support\Facades\Storage::fake('public');
    $artworkService = app(App\Services\Design\ArtifactArtworkService::class);
    $asset = $artworkService->store(Illuminate\Http\UploadedFile::fake()->image('sample.png', 540, 850), $this->tenant->id, $this->event->id, 'badge-background');
    $original = $artworkService->contents($asset->disk, $asset->path);
    $this->template->update(['background_disk' => $asset->disk, 'background_path' => $asset->path, 'logo_source' => 'custom', 'logo_disk' => $asset->disk, 'logo_path' => $asset->path,
        'back' => ['layout' => $this->template->layoutSettings(), 'background_disk' => $asset->disk, 'background_path' => $asset->path]]);
    $host = eventSubdomainHost($this->tenant->slug);
    $base = "http://{$host}/events/{$this->event->id}";
    $this->actingAs($this->actor)->withHeaders(['HTTP_HOST' => $host]);
    $data = $this->getJson($base.'/data/badges')->assertOk()->json();
    foreach (['back_background', 'badge_logo', 'custom_logo'] as $key) {
        expect(base64_decode(explode(',', $data[$key], 2)[1], true))->not->toBe($original);
    }
    expect($this->get($base.'/badges/template/artwork')->assertOk()->getContent())->not->toBe($original)
        ->and($this->purchase->licence->fresh()->first_used_at)->toBeNull();
    $this->postJson($base.'/badges/clean-preview', ['confirm_paid_use' => true, 'output_key' => (string) Str::uuid()])->assertOk();
    expect($this->get($base.'/badges/template/artwork')->assertOk()->getContent())->toBe($original);
    $this->purchase->licence->update(['state' => 'suspended']);
    expect($this->get($base.'/badges/template/artwork')->assertOk()->getContent())->not->toBe($original);
});

test('an archived event with digital purchase history explains why permanent purge is blocked', function (): void {
    $this->event->delete();
    $reason = null;
    expect($this->event->canBeDeleted($reason))->toBeFalse()->and($reason)->toContain('licence history');
});

test('one paid event licence prints fifty nine badges on A4 in portrait duplex and custom landscape', function (): void {
    $tickets = collect(['Doctor', 'Nurse', 'Physician Assistant', 'Faculty', 'Host'])->map(fn (string $name) => App\Models\EventTicketType::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'name' => $name]));
    $registrations = collect();
    for ($index = 0; $index < 59; $index++) {
        $registrations->push(App\Models\EventRegistration::factory()->create(['tenant_id' => $this->tenant->id, 'event_id' => $this->event->id, 'ticket_type_id' => $tickets[$index % 5]->id,
            'full_name' => $index === 0 ? 'Alexandria Nana Akwasi Agyeman-Ababio' : 'Synthetic Person '.($index + 1), 'email' => 'badge-'.$index.'@example.test']));
    }
    $layout = $this->template->layoutSettings();
    $layout['qr']['visible'] = false;
    $this->template->update(['width_mm' => 54, 'height_mm' => 85, 'orientation' => 'portrait', 'layout' => $layout]);
    $modes = [['width' => 54, 'height' => 85, 'back' => false, 'flip' => 'long-edge', 'pages' => 7],
        ['width' => 54, 'height' => 85, 'back' => true, 'flip' => 'long-edge', 'pages' => 14],
        ['width' => 54, 'height' => 85, 'back' => true, 'flip' => 'short-edge', 'pages' => 14],
        ['width' => 85, 'height' => 54, 'back' => false, 'flip' => 'long-edge', 'pages' => 8]];
    foreach ($modes as $mode) {
        $this->template->update(['width_mm' => $mode['width'], 'height_mm' => $mode['height'], 'back' => $mode['back'] ? ['layout' => $layout] : null,
            'sheet_settings' => ['paper' => 'a4', 'margin_mm' => 8, 'gap_mm' => 3, 'crop_marks' => true, 'duplex' => $mode['flip']], 'design_version' => $this->template->design_version + 1]);
        $claim = app(BadgeTemplateAccess::class)->claimUse($this->event, $this->actor, [], 'badge_pdf', (string) Str::uuid());
        $pdf = app(App\Services\Badges\BadgePdfService::class)->generate($this->event, $claim['template'], $registrations, $claim['uses']);
        expect($pdf->output())->toStartWith('%PDF-');
        expect($pdf->getDomPDF()->getCanvas()->get_page_count())->toBe($mode['pages']);
        unset($pdf);
    }
    expect(BadgeTemplatePurchase::query()->where('event_id', $this->event->id)->count())->toBe(1)
        ->and(BadgeTemplateUse::query()->where('purchase_id', $this->purchase->id)->count())->toBe(4);
});
