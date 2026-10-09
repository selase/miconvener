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

test('the hero renders three labelled panels and preserves the event-day fallback', function (): void {
    $response = $this->get('/')->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    $tabs = $xpath->query('//*[@data-hero-showcase]//*[@role="tab"]');

    expect($tabs->length)->toBe(3);
    foreach ($tabs as $tab) {
        $panel = $document->getElementById($tab->getAttribute('aria-controls'));
        expect($panel)->not->toBeNull()
            ->and($panel->getAttribute('aria-labelledby'))->toBe($tab->getAttribute('id'))
            ->and($panel->hasAttribute('hidden'))->toBe($tab->getAttribute('aria-selected') !== 'true');
    }

    expect($document->getElementById('hero-panel-run')->textContent)->toContain('Overview');
    $response->assertSee('Plan your event')->assertSee('Follow through');
});
