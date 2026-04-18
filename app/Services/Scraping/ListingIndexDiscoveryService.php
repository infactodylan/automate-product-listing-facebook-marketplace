<?php

namespace App\Services\Scraping;

use App\Services\OpenAi\ListingIndexOpenAiDiscoverer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingIndexDiscoveryService
{
    public function __construct(
        private ListingIndexExtractor $indexExtractor,
        private ListingIndexOpenAiDiscoverer $openAiDiscoverer,
    ) {}

    /**
     * When OpenAI is enabled and configured, listing links are discovered with
     * web_search first. HTTP link parsing is only a fallback.
     *
     * @return list<string>
     */
    public function discoverListingUrls(string $listingPageUrl): array
    {
        $max = (int) config('facebook_marketplace.max_listings_per_job');

        $useOpenAi = config('openai.listing_index_openai_enabled')
            && $this->openAiDiscoverer->isConfigured();

        if ($useOpenAi) {
            Log::info('Listing index discovery', [
                'phase' => 'openai_attempt',
                'inventory_url' => $listingPageUrl,
                'max_listings' => $max,
            ]);
            try {
                $fromOpenAi = $this->openAiDiscoverer->discoverListingUrls($listingPageUrl, $max);
                if ($fromOpenAi !== []) {
                    Log::info('Listing index discovery', [
                        'phase' => 'openai_complete',
                        'inventory_url' => $listingPageUrl,
                        'listing_url_count' => count($fromOpenAi),
                    ]);

                    return $fromOpenAi;
                }

                Log::info('Listing index discovery', [
                    'phase' => 'openai_empty_fallback_http',
                    'inventory_url' => $listingPageUrl,
                ]);
            } catch (\Throwable $e) {
                Log::warning('OpenAI listing index discovery failed; using HTTP link extraction', [
                    'url' => $listingPageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Listing index discovery', [
            'phase' => 'http_extract',
            'inventory_url' => $listingPageUrl,
        ]);

        return $this->discoverUrlsViaHttp($listingPageUrl, $max);
    }

    /**
     * @return list<string>
     */
    private function discoverUrlsViaHttp(string $listingPageUrl, int $max): array
    {
        $response = Http::withHeaders([
            'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
        ])
            ->timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->get($listingPageUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Listings page returned HTTP '.$response->status().'.');
        }

        $html = $response->body();

        $urls = $this->indexExtractor->extractCandidateListingUrls($listingPageUrl, $html, $max);

        Log::info('Listing index discovery', [
            'phase' => 'http_complete',
            'inventory_url' => $listingPageUrl,
            'listing_url_count' => count($urls),
        ]);

        return $urls;
    }
}
