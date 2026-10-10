<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use DOMDocument;
use DOMXPath;

/**
 * The landing page is the first thing every visitor and every shared link
 * reaches, and it is assembled from config plus a Livewire pricing component --
 * so a renaming in config or a missing key takes the homepage down rather than
 * degrading. These assertions are deliberately about substance, not markup.
 */
test('the landing page renders', function (): void {
    $this->get('/')->assertOk();
});

test('the hero uses the configured platform overview', function (): void {
    config(['product-page.hero.subtitle' => 'Bring programmes & places together.']);

    $this->get('/')->assertOk()->assertSee('Bring programmes &amp; places together.', false);
});

test('venue and engagement copy covers the supported workflows', function (): void {
    $this->get('/')->assertOk()
        ->assertSee('Create facility tasks')
        ->assertDontSee('Assign facility tasks')
        ->assertSee('Discussions &amp; forums', false);
});

test('the landing page describes the event product, not the one this codebase replaced', function (): void {
    $response = $this->get('/');

    $response->assertSee('Run the whole event', false);
    $response->assertSee('Settlement statement', false);

    foreach (['diarization', 'Transcribe', 'AI Minutes', 'Jira', 'Asana', 'Legal Hold', 'e-Discovery'] as $stale) {
        $response->assertDontSee($stale, false);
    }
});

test('the landing page shows prices in the currency that is actually charged', function (): void {
    // The page previously rendered a hardcoded dollar sign while checkout billed
    // another currency, so the amount advertised was not the amount taken.
    $this->get('/')->assertSee(config('services.paystack.currency', 'GHS'), false);
});

test('the enterprise page renders', function (): void {
    $this->get('/product-enterprise')->assertOk();
});

test('the landing page leaves the marketplace out of navigation and footer until real venues take bookings', function (): void {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertDontSee('/marketplace', false);
});

test('the hero renders five labelled panels and preserves the event-day fallback', function (): void {
    $response = $this->get('/')->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $tabs = $xpath->query('//*[@data-hero-showcase]//*[@role="tab"]');

    expect($tabs->length)->toBe(5);
    foreach ($tabs as $tab) {
        $panel = $document->getElementById($tab->getAttribute('aria-controls'));
        expect($panel)->not->toBeNull()
            ->and($panel->getAttribute('aria-labelledby'))->toBe($tab->getAttribute('id'))
            ->and($panel->hasAttribute('hidden'))->toBe($tab->getAttribute('aria-selected') !== 'true');
    }

    expect($document->getElementById('hero-panel-run')->textContent)->toContain('Overview');
    $response->assertSee('Plan &amp; sell', false)->assertSee('Venues &amp; services', false);
    expect($document->getElementById('hero-panel-venues')->textContent)->toContain('Keep every space ready');
});

test('the homepage introduces the four audiences it serves', function (): void {
    $this->get('/')->assertOk()
        ->assertSee('Built for everyone behind the gathering')
        ->assertSee('Event organisers')
        ->assertSee('Door &amp; floor teams', false)
        ->assertSee('Venue operators')
        ->assertSee('Attendees &amp; participants', false)
        ->assertDontSee('From first invite to final payout');
});

test('the homepage explains facilities recognition and academic coordination', function (): void {
    $page = $this->get('/')->assertOk();
    foreach ([
        'Keep every space ready for the next gathering.',
        'Academic submissions &amp; peer review',
        'Receive abstracts, assign reviewers, record decisions and export an abstract book.',
        'Sponsor commitments',
        'Certificate verification',
        'miconvener.com/my',
        'Know what came in—and what you receive.',
    ] as $copy) {
        expect(str_contains($page->getContent(), $copy))->toBeTrue("Missing homepage coverage: {$copy}");
    }
});

test('homepage feature groups are labelled and each capability has one home', function (): void {
    $items = collect(config('product-page.capabilities.items'));
    expect($items->pluck('group')->unique()->count())->toBe(6)
        ->and($items->pluck('title')->unique()->count())->toBe($items->count());
    $page = $this->get('/')->assertOk();
    foreach ($items->pluck('group')->unique() as $group) {
        expect(str_contains($page->getContent(), e($group)))->toBeTrue();
    }
});
