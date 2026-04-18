<?php

namespace App\Services\Scraping;

use App\Services\OpenAi\ListingIndexOpenAiDiscoverer;
use Illuminate\Support\Facades\Log;

class ListingIndexDiscoveryService
{
    public function __construct(
        private ListingIndexExtractor $indexExtractor,
        private ListingIndexOpenAiDiscoverer $openAiDiscoverer,
        private RenderedHtmlFetcher $renderedHtml,
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
            try {
                $fromOpenAi = $this->openAiDiscoverer->discoverListingUrls($listingPageUrl, $max);
                if ($fromOpenAi !== []) {
                    $this->logListingCrawlPlan($listingPageUrl, $fromOpenAi, 'openai');

                    return $fromOpenAi;
                }
            } catch (\Throwable $e) {
                Log::warning('OpenAI listing index discovery failed; using HTTP link extraction', [
                    'inventory_url' => $listingPageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $urls = $this->discoverUrlsViaHttp($listingPageUrl, $max);
        $this->logListingCrawlPlan($listingPageUrl, $urls, 'http');

        return $urls;
    }

    /**
     * @param  list<string>  $detailUrls
     */
    private function logListingCrawlPlan(string $inventoryUrl, array $detailUrls, string $discoverySource): void
    {
        Log::info('Listing crawl plan', [
            'inventory_url' => $inventoryUrl,
            'discovery_source' => $discoverySource,
            'detail_page_count' => count($detailUrls),
            'detail_urls' => array_values($detailUrls),
        ]);
    }

    /**
     * @return list<string>
     */
    private function discoverUrlsViaHttp(string $listingPageUrl, int $max): array
    {
        $html = $this->renderedHtml->fetch($listingPageUrl);

        return $this->indexExtractor->extractCandidateListingUrls($listingPageUrl, $html, $max);
    }
}
