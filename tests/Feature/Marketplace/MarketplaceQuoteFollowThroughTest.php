<?php

declare(strict_types=1);

use App\Jobs\EmbedStoreListingJob;
use App\Mail\Marketplace\NewQuoteRequestNotification;
use App\Mail\Marketplace\QuoteProposalReady;
use App\Models\MarketplaceQuote;
use App\Models\Shop;
use App\Models\StoreListing;
use App\Models\Tenant;
use App\Services\Marketplace\MarketplaceVendorService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

/**
 * The quote and search features were built with no one told anything and
 * nothing kept current: a vendor never heard about a request, a planner never
 * heard a quote was ready, and a listing published after the one-off embedding
 * run was invisible to search.
 */
function quoteVendorListing(): StoreListing
{
    $tenant = Tenant::factory()->create(['slug' => 'quote-vendor', 'isolation_mode' => 'shared']);
    $shop = Shop::factory()->create(['tenant_id' => $tenant->id, 'email' => 'vendor@example.com']);

    return StoreListing::factory()->published()->create(['shop_id' => $shop->id]);
}

test('a vendor is emailed when a planner asks for a quote, with a link to answer it', function (): void {
    Mail::fake();
    $listing = quoteVendorListing();

    $this->post(route('marketplace.quotes.rfq', ['slug' => $listing->slug]), [
        'planner_name' => 'Kofi Planner',
        'planner_email' => 'kofi@example.com',
        'requirements_description' => 'Line array PA for 800 guests.',
    ])->assertRedirect();

    $quote = MarketplaceQuote::query()->firstOrFail();

    Mail::assertQueued(NewQuoteRequestNotification::class, fn (NewQuoteRequestNotification $mail): bool => $mail->hasTo('vendor@example.com')
        && str_contains($mail->inboxUrl, "/venue/quotes/{$quote->id}"));
});

test('a planner is emailed when the vendor sends a proposal, with a link to accept it', function (): void {
    Mail::fake();
    $listing = quoteVendorListing();
    $service = app(MarketplaceVendorService::class);

    $quote = $service->createRfq($listing->load('shop'), [
        'planner_name' => 'Kofi Planner',
        'planner_email' => 'kofi@example.com',
        'requirements_description' => 'Line array PA for 800 guests.',
    ]);

    $service->submitProposal($quote, [
        'items' => [['description' => 'Line array, one day', 'quantity' => 1, 'unit_price_pesewas' => 500000]],
    ]);

    Mail::assertQueued(QuoteProposalReady::class, fn (QuoteProposalReady $mail): bool => $mail->hasTo('kofi@example.com')
        && str_ends_with($mail->quoteUrl, "/quotes/{$quote->quote_reference}"));
});

test('the vendor and public quote screens render', function (): void {
    $listing = quoteVendorListing();
    $quote = app(MarketplaceVendorService::class)->createRfq($listing->load('shop'), [
        'planner_name' => 'Kofi Planner',
        'planner_email' => 'kofi@example.com',
        'requirements_description' => 'Line array PA for 800 guests.',
    ]);

    $this->get(route('marketplace.quotes.show', ['reference' => $quote->quote_reference]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Public/Marketplace/Quotes/Show')->where('quote.status', 'pending_quote'));
});

test('publishing or editing a listing refreshes its search embedding', function (): void {
    Bus::fake([EmbedStoreListingJob::class]);
    $listing = quoteVendorListing();

    Bus::assertDispatchedTimes(EmbedStoreListingJob::class, 1);

    $listing->update(['title' => 'Concert line array, renamed']);
    Bus::assertDispatchedTimes(EmbedStoreListingJob::class, 2);

    // Saving the embedding itself must not queue another run.
    $listing->update(['embedding' => array_fill(0, 1536, 0.0)]);
    Bus::assertDispatchedTimes(EmbedStoreListingJob::class, 2);
});

test('a draft listing is not embedded', function (): void {
    Bus::fake([EmbedStoreListingJob::class]);
    $shop = Shop::factory()->create();

    StoreListing::factory()->create(['shop_id' => $shop->id]);

    Bus::assertNotDispatched(EmbedStoreListingJob::class);
});

test('the embedding job stores a vector on the listing', function (): void {
    $listing = quoteVendorListing();
    $listing->forceFill(['embedding' => null])->saveQuietly();

    new EmbedStoreListingJob($listing->id)->handle(app(App\Services\Marketplace\VenueEmbeddingService::class));

    expect($listing->fresh()->getRawOriginal('embedding'))->not->toBeNull();
});

test('no Inertia page is committed as an empty file', function (): void {
    // Four pages were committed at 0 bytes and rendered blank in production.
    $empty = collect(File::allFiles(resource_path('js/Pages')))
        ->filter(fn (SplFileInfo $file): bool => $file->getSize() === 0)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($empty)->toBe([]);
});
