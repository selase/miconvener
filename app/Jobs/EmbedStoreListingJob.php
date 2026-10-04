<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StoreListing;
use App\Services\Marketplace\VenueEmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Refreshes one listing's search embedding after it is published or edited,
 * so marketplace search finds new and changed listings without anyone having
 * to run marketplace:embed-venues by hand.
 */
final class EmbedStoreListingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $listingId)
    {
        $this->afterCommit();
    }

    public function handle(VenueEmbeddingService $embeddings): void
    {
        $listing = StoreListing::query()->find($this->listingId);

        if ($listing === null || $listing->status !== StoreListing::STATUS_PUBLISHED) {
            return;
        }

        $embeddings->embedListing($listing);
    }
}
