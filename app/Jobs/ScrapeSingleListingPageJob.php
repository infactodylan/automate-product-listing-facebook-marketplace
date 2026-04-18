<?php

namespace App\Jobs;

use App\Models\ListingExportScrapeResult;
use App\Services\Scraping\ListingPageScraper;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeSingleListingPageJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public int $listingExportId,
        public string $listingUrl,
        public int $position,
    ) {}

    public function handle(ListingPageScraper $scraper): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $product = $scraper->scrape($this->listingUrl, $this->listingExportId);

            ListingExportScrapeResult::query()->updateOrCreate(
                [
                    'listing_export_id' => $this->listingExportId,
                    'position' => $this->position,
                ],
                [
                    'source_url' => $this->listingUrl,
                    'product' => $product,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Listing scrape failed', [
                'export_id' => $this->listingExportId,
                'url' => $this->listingUrl,
                'error' => $e->getMessage(),
            ]);

            ListingExportScrapeResult::query()->updateOrCreate(
                [
                    'listing_export_id' => $this->listingExportId,
                    'position' => $this->position,
                ],
                [
                    'source_url' => $this->listingUrl,
                    'product' => null,
                ],
            );
        }
    }
}
