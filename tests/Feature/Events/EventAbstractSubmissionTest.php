<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('public user can view abstract submission page', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'science-conf-2026',
    ]);

    $response = $this->get("http://{$host}/e/{$event->slug}/abstracts/submit", ['HTTP_HOST' => $host]);

    $response->assertOk();
});

test('author can submit structured abstract with multiple authors and file manuscript', function () {
    Storage::fake('public');

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-summit-2026',
    ]);

    $file = UploadedFile::fake()->create('manuscript.pdf', 500, 'application/pdf');

    $response = $this->postJson("http://{$host}/e/{$event->slug}/abstracts/submit", [
        'title' => 'Long-Term Outcomes of Novel Anticoagulants in Atrial Fibrillation',
        'track' => 'Clinical Cardiology',
        'presentation_preference' => 'oral',
        'structured_abstract' => [
            'background' => 'Atrial fibrillation affects millions worldwide and poses high stroke risk.',
            'methods' => 'A randomized observational cohort of 1,200 patients followed for 36 months.',
            'results' => 'Incidence of major thromboembolism reduced by 42% compared to historical controls (p < 0.001).',
            'conclusion' => 'Novel protocols exhibit superior safety profiles with reduced hospitalization rates.',
        ],
        'keywords' => ['Atrial Fibrillation', 'Anticoagulants', 'Cardiology'],
        'conflict_of_interest' => 'None declared',
        'file' => $file,
        'authors' => [
            [
                'first_name' => 'Kofi',
                'last_name' => 'Mensah',
                'email' => 'kmensah@cardio.org',
                'affiliation' => 'Korle Bu Teaching Hospital',
                'country' => 'Ghana',
                'is_presenting' => true,
                'is_corresponding' => true,
            ],
            [
                'first_name' => 'Sarah',
                'last_name' => 'Jenkins',
                'email' => 'sjenkins@oxford.ac.uk',
                'affiliation' => 'Oxford University',
                'country' => 'United Kingdom',
                'is_presenting' => false,
                'is_corresponding' => false,
            ],
        ],
    ], ['HTTP_HOST' => $host]);

    $response->assertCreated();
    $response->assertJsonStructure([
        'success',
        'code',
        'message',
        'tracking_url',
    ]);

    $code = $response->json('code');
    expect($code)->toStartWith('ABS-');

    $this->assertDatabaseHas('event_abstracts', [
        'event_id' => $event->id,
        'code' => $code,
        'title' => 'Long-Term Outcomes of Novel Anticoagulants in Atrial Fibrillation',
        'track' => 'Clinical Cardiology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_SUBMITTED,
    ], 'landlord');

    $abstract = EventAbstract::where('code', $code)->first();
    expect($abstract->authors)->toHaveCount(2);
    expect($abstract->presentingAuthor->email)->toBe('kmensah@cardio.org');
});

test('public user can track abstract status using code', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'cardio-summit-2026',
    ]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-TRACK1',
        'title' => 'Genomic Markers in Pediatric Oncology',
        'track' => 'Pediatrics',
        'presentation_preference' => 'either',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
    ]);

    $abstract->authors()->create([
        'first_name' => 'Ama',
        'last_name' => 'Adjei',
        'email' => 'ama@health.gov',
        'affiliation' => 'Noguchi Memorial Institute',
        'is_presenting' => true,
        'sort_order' => 0,
    ]);

    $response = $this->getJson("http://{$host}/e/{$event->slug}/abstracts/{$abstract->code}", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('abstract.code', 'ABS-TRACK1');
    $response->assertJsonPath('abstract.status', 'under_review');
    $response->assertJsonPath('abstract.authors.0.name', 'Ama Adjei');
});

test('abstract codes are unique across tenants, not just within one', function () {
    $tenantA = Tenant::factory()->create(['slug' => 'code-a', 'isolation_mode' => 'shared']);
    $tenantB = Tenant::factory()->create(['slug' => 'code-b', 'isolation_mode' => 'shared']);

    $eventA = Event::factory()->published()->create(['tenant_id' => $tenantA->id]);

    EventAbstract::create([
        'tenant_id' => $tenantA->id,
        'event_id' => $eventA->id,
        'code' => 'ABS-TAKEN',
        'title' => 'Existing',
        'body' => 'Body',
        'status' => EventAbstract::STATUS_SUBMITTED,
    ]);

    // The column is globally unique, so the probe must see across tenants. With
    // the tenant scope applied it cannot, and hands back a code that will
    // violate the index on insert.
    app(TenantContext::class)->setTenant($tenantB);

    expect(EventAbstract::codeExists('ABS-TAKEN'))->toBeTrue();
});
