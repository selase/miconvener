<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StoreListing;
use App\Services\Marketplace\VenueEmbeddingService;
use Illuminate\Console\Command;

final class EmbedMarketplaceVenuesCommand extends Command
{
    protected $signature = 'marketplace:embed-venues
                            {--force : Re-generate embeddings even if a vector is already present}';

    protected $description = 'Generate and index high-dimensional vector embeddings for published marketplace venue spaces';

    public function handle(VenueEmbeddingService $embeddingService): int
    {
        $force = (bool) $this->option('force');

        $query = StoreListing::query()
            ->where('listing_kind', StoreListing::KIND_VENUE)
            ->where('status', StoreListing::STATUS_PUBLISHED)
            ->with(['shop', 'amenities.amenity']);

        if (! $force) {
            $query->whereNull('embedding');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No venue spaces need embedding. Use --force to re-embed all spaces.');

            return self::SUCCESS;
        }

        $this->info("Generating embeddings for {$total} venue spaces...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $successCount = 0;

        $query->chunkById(50, function ($listings) use ($embeddingService, $bar, &$successCount): void {
            foreach ($listings as $listing) {
                if ($embeddingService->embedListing($listing)) {
                    $successCount++;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Successfully generated and saved embeddings for {$successCount} venue spaces.");

        return self::SUCCESS;
    }
}
