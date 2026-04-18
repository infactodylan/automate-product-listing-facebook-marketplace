<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Services\Scraping\ListingIndexDiscoveryService;
use App\Services\UrlSafetyValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchListingIndexJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    public function __construct(public int $listingExportId) {}

    public function handle(UrlSafetyValidator $urlSafety, ListingIndexDiscoveryService $indexDiscovery): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        $export->update([
            'status' => ListingExport::STATUS_FETCHING_INDEX,
            'error_message' => null,
        ]);

        $urlSafety->assertPublicHttpUrl($export->listing_page_url);

        $urls = $indexDiscovery->discoverListingUrls($export->listing_page_url);

        Log::info('Listing index extracted', [
            'export_id' => $export->id,
            'domain' => parse_url($export->listing_page_url, PHP_URL_HOST),
            'count' => count($urls),
        ]);

        $export->update([
            'discovered_urls' => $urls,
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
            'error_message' => $exception?->getMessage() ?? 'Failed while fetching the listings index.',
        ]);
    }
}
