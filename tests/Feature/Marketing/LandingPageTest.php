<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

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
