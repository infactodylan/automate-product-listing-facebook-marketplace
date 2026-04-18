<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Services\Scraping\ListingPageScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public int $listingExportId) {}

    public function handle(ListingPageScraper $scraper): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        $export->update([
            'status' => ListingExport::STATUS_SCRAPING_LISTINGS,
            'error_message' => null,
        ]);

        /** @var list<string>|null $urls */
        $urls = $export->discovered_urls;
        $urls ??= [];

        if ($urls === []) {
            $urls = [$export->listing_page_url];
        }

        $products = [];
        foreach ($urls as $listingUrl) {
            try {
                Log::info('Listing scrape job: scraping URL', [
                    'export_id' => $export->id,
                    'listing_url' => $listingUrl,
                ]);
                $products[] = $scraper->scrape($listingUrl, $export->id);
            } catch (\Throwable $e) {
                Log::warning('Listing scrape failed', [
                    'export_id' => $export->id,
                    'url' => $listingUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($products === []) {
            throw new \RuntimeException('No listings could be parsed. The site may block automated access, require JavaScript rendering, or use an unsupported layout.');
        }

        $export->update([
            'scraped_products' => $products,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $export = ListingExport::query()->find($this->listingExportId);
        if (! $export) {
            return;
        }

        $export->update([
            'status' => ListingExport::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Failed while scraping listings.',
        ]);
    }
}
