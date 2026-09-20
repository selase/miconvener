<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Mail\Events\AbstractDecisionNotificationMail;
use App\Models\Event;
use App\Models\EventAbstract;
use App\Models\EventAbstractReview;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    refreshTenantDatabases();
    Artisan::call('db:seed', ['--class' => 'RoleSeeder']);
    Artisan::call('db:seed', ['--class' => 'PermissionsSeeder']);
});

test('organizer can view abstracts dashboard and filter by track or status', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-annual-2026',
    ]);

    $abs1 = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-001',
        'title' => 'Advances in Robotic Surgery',
        'track' => 'Surgery',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_SUBMITTED,
    ]);

    $abs2 = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-002',
        'title' => 'Pediatric Intensive Care Outcomes',
        'track' => 'Pediatrics',
        'presentation_preference' => 'poster',
        'status' => EventAbstract::STATUS_ACCEPTED_POSTER,
    ]);

    $response = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/data/abstracts", ['HTTP_HOST' => $host]);

    $response->assertOk();
    $response->assertJsonPath('stats.total', 2);
    $response->assertJsonPath('stats.submitted', 1);
    $response->assertJsonPath('stats.accepted_poster', 1);

    // Test status filter
    $filterRes = $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/data/abstracts?status=accepted_poster", ['HTTP_HOST' => $host]);

    $filterRes->assertOk();
    expect($filterRes->json('abstracts'))->toHaveCount(1);
    expect($filterRes->json('abstracts.0.code'))->toBe('ABS-002');
});

test('organizer can assign and remove peer reviewer', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-annual-2026',
    ]);

    $reviewer = User::factory()->create(['tenant_id' => $tenant->id]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-REV1',
        'title' => 'Genomic Sequencing of Resistant Pathogens',
        'track' => 'Microbiology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_SUBMITTED,
    ]);

    // Assign reviewer
    $assignRes = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/abstracts/{$abstract->id}/assign-reviewer", [
            'reviewer_id' => $reviewer->id,
        ], ['HTTP_HOST' => $host]);

    $assignRes->assertOk();
    $this->assertDatabaseHas('event_abstract_reviews', [
        'abstract_id' => $abstract->id,
        'reviewer_id' => $reviewer->id,
        'status' => EventAbstractReview::STATUS_PENDING,
    ], 'landlord');

    expect($abstract->fresh()->status)->toBe(EventAbstract::STATUS_UNDER_REVIEW);

    $review = EventAbstractReview::where('abstract_id', $abstract->id)->first();

    // Remove reviewer
    $removeRes = $this->actingAs($user)
        ->deleteJson("http://{$host}/events/{$event->id}/abstracts/{$abstract->id}/reviews/{$review->id}", [], ['HTTP_HOST' => $host]);

    $removeRes->assertOk();
    $this->assertDatabaseMissing('event_abstract_reviews', ['id' => $review->id], 'landlord');
    expect($abstract->fresh()->status)->toBe(EventAbstract::STATUS_SUBMITTED);
});

test('peer reviewer can view assigned abstracts and submit rubric evaluation', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-annual-2026',
    ]);

    $reviewer = User::factory()->create(['tenant_id' => $tenant->id]);
    $tenant->users()->attach($reviewer->id);
    setPermissionsTeamId($tenant->id);
    $reviewer->assignRole('Org Admin');

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-RUBRIC1',
        'title' => 'Digital Therapeutics in Type 2 Diabetes',
        'track' => 'Endocrinology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
    ]);

    $review = EventAbstractReview::create([
        'tenant_id' => $tenant->id,
        'abstract_id' => $abstract->id,
        'reviewer_id' => $reviewer->id,
        'status' => EventAbstractReview::STATUS_PENDING,
    ]);

    // Reviewer lists assigned reviews
    $listRes = $this->actingAs($reviewer)
        ->getJson("http://{$host}/events/{$event->id}/reviews", ['HTTP_HOST' => $host]);

    $listRes->assertOk();
    expect($listRes->json('reviews'))->toHaveCount(1);
    expect($listRes->json('reviews.0.abstract.code'))->toBe('ABS-RUBRIC1');

    // Submit evaluation with rubric scores (1 to 5)
    $submitRes = $this->actingAs($reviewer)
        ->postJson("http://{$host}/events/{$event->id}/abstracts/{$abstract->id}/reviews", [
            'novelty_score' => 5,
            'methodology_score' => 4,
            'relevance_score' => 5,
            'clarity_score' => 4,
            'recommendation' => 'accept_oral',
            'comments_to_author' => 'Rigorous methodology with clear clinical relevance.',
            'confidential_comments' => 'Strong candidate for oral presentation session.',
        ], ['HTTP_HOST' => $host]);

    $submitRes->assertOk();
    $review->refresh();

    expect($review->status)->toBe(EventAbstractReview::STATUS_COMPLETED);
    expect((float) $review->total_score)->toBe(4.50); // (5+4+5+4)/4 = 4.5
    expect($review->recommendation)->toBe('accept_oral');
    expect($abstract->fresh()->averageScore())->toBe(4.50);
});

test('organizer can record decision and dispatch author notification email', function () {
    Mail::fake();

    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-annual-2026',
    ]);

    $abstract = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-DECIDE1',
        'title' => 'Machine Learning in Radiomics',
        'track' => 'Radiology',
        'presentation_preference' => 'oral',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
    ]);

    $author = $abstract->authors()->create([
        'first_name' => 'Yaw',
        'last_name' => 'Boateng',
        'email' => 'yboateng@radiology.gh',
        'affiliation' => 'Ghana Health Service',
        'is_presenting' => true,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/abstracts/{$abstract->id}/decision", [
            'status' => EventAbstract::STATUS_ACCEPTED_ORAL,
            'decision_notes' => 'Selected for Plenary Oral Session on Day 2.',
            'notify_author' => true,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($abstract->fresh()->status)->toBe(EventAbstract::STATUS_ACCEPTED_ORAL);
    expect($abstract->fresh()->decision_notes)->toBe('Selected for Plenary Oral Session on Day 2.');
    expect((int) $abstract->fresh()->decided_by)->toBe((int) $user->id);

    Mail::assertSent(AbstractDecisionNotificationMail::class, function ($mail) {
        return $mail->hasTo('yboateng@radiology.gh');
    });
});

test('organizer can record bulk decisions on abstracts', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create([
        'tenant_id' => $tenant->id,
        'slug' => 'med-annual-2026',
    ]);

    $abs1 = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-BULK1',
        'title' => 'Paper A',
        'presentation_preference' => 'poster',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
    ]);

    $abs2 = EventAbstract::create([
        'tenant_id' => $tenant->id,
        'event_id' => $event->id,
        'code' => 'ABS-BULK2',
        'title' => 'Paper B',
        'presentation_preference' => 'poster',
        'status' => EventAbstract::STATUS_UNDER_REVIEW,
    ]);

    $response = $this->actingAs($user)
        ->postJson("http://{$host}/events/{$event->id}/abstracts/bulk-decision", [
            'abstract_ids' => [$abs1->id, $abs2->id],
            'status' => EventAbstract::STATUS_ACCEPTED_POSTER,
            'decision_notes' => 'Accepted for Poster Hall Session.',
            'notify_authors' => false,
        ], ['HTTP_HOST' => $host]);

    $response->assertOk();
    expect($abs1->fresh()->status)->toBe(EventAbstract::STATUS_ACCEPTED_POSTER);
    expect($abs2->fresh()->status)->toBe(EventAbstract::STATUS_ACCEPTED_POSTER);
});

test('the abstracts page lists distinct tracks with a query Postgres accepts', function () {
    [$tenant, $user] = eventHost('acme');
    $host = eventSubdomainHost('acme');

    $event = Event::factory()->published()->create(['tenant_id' => $tenant->id]);

    foreach ([['ABS-T1', 'Surgery'], ['ABS-T2', 'Pediatrics'], ['ABS-T3', 'Surgery']] as [$code, $track]) {
        EventAbstract::create([
            'tenant_id' => $tenant->id,
            'event_id' => $event->id,
            'code' => $code,
            'title' => "Abstract {$code}",
            'track' => $track,
            'presentation_preference' => 'oral',
            'status' => EventAbstract::STATUS_SUBMITTED,
        ]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->actingAs($user)
        ->getJson("http://{$host}/events/{$event->id}/data/abstracts", ['HTTP_HOST' => $host])
        ->assertOk()
        ->assertJsonPath('tracks', ['Pediatrics', 'Surgery']);

    /*
     * The abstracts() relation always orders by created_at. Taking distinct
     * tracks through it produced SELECT DISTINCT track ... ORDER BY
     * created_at, which Postgres rejects — the Abstracts tab returned 500 in
     * production. SQLite accepts that SQL, so the response alone cannot catch
     * it here; the query that ran has to be inspected.
     */
    $distinctWithForeignOrder = array_filter($queries, fn (string $sql): bool => (bool) preg_match('/select distinct "track".*order by "created_at"/i', $sql));

    expect($distinctWithForeignOrder)->toBe([]);
});
