<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Services\Scraping\ListingIndexExtractor;
use App\Services\UrlSafetyValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
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

    public function handle(UrlSafetyValidator $urlSafety, ListingIndexExtractor $indexExtractor): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        $export->update([
            'status' => ListingExport::STATUS_FETCHING_INDEX,
            'error_message' => null,
        ]);

        $urlSafety->assertPublicHttpUrl($export->listing_page_url);

        $response = Http::withHeaders([
            'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
        ])
            ->timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->get($export->listing_page_url);

        if (! $response->successful()) {
            throw new \RuntimeException('Listings page returned HTTP '.$response->status().'.');
        }

        $html = $response->body();
        $max = (int) config('facebook_marketplace.max_listings_per_job');

        $urls = $indexExtractor->extractCandidateListingUrls($export->listing_page_url, $html, $max);

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
