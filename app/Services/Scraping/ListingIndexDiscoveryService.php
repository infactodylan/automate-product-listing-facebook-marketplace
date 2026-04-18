<?php

namespace App\Services\Scraping;

use App\Services\OpenAi\ListingIndexOpenAiDiscoverer;
use App\Services\OpenAi\ListingInventoryCatalogOpenAi;
use Illuminate\Support\Facades\Log;

/**
 * Discovers listing detail URLs from an inventory index page:
 * 1. Optional: GET HTML → OpenAI + JSON-LD ({@see ListingInventoryCatalogOpenAi})
 * 2. Optional: OpenAI web_search + same-page filter ({@see ListingIndexOpenAiDiscoverer})
 * 3. Fallback: parse listing links from the same GET HTML ({@see ListingIndexExtractor})
 */
class ListingIndexDiscoveryService
{
    public function __construct(
        private ListingIndexExtractor $indexExtractor,
        private ListingIndexOpenAiDiscoverer $openAiDiscoverer,
        private ListingInventoryCatalogOpenAi $inventoryCatalogOpenAi,
        private RenderedHtmlFetcher $renderedHtml,
    ) {}

    /**
     * @return list<string>
     */
    public function discoverListingUrls(string $listingPageUrl): array
    {
        $max = (int) config('facebook_marketplace.max_listings_per_job');

        $htmlCache = null;

        $catalogEnabled = config('openai.listing_inventory_catalog_enabled')
            && $this->inventoryCatalogOpenAi->isConfigured();

        if ($catalogEnabled) {
            try {
                $htmlCache = $this->renderedHtml->fetch($listingPageUrl);
                $candidateUrls = $this->inventoryCatalogOpenAi->extractListingUrlsFromInventoryHtml(
                    $listingPageUrl,
                    $htmlCache,
                    $max,
                );
                $filtered = $this->indexExtractor->filterListingUrls($listingPageUrl, $candidateUrls, $max);
                if ($filtered !== []) {
                    $this->logListingCrawlPlan($listingPageUrl, $filtered, 'openai_inventory_catalog');

                    return $filtered;
                }
            } catch (\Throwable $e) {
                Log::warning('OpenAI inventory catalog (HTML + JSON-LD) failed; continuing with other discovery', [
                    'inventory_url' => $listingPageUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }

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

        $html = $htmlCache ?? $this->renderedHtml->fetch($listingPageUrl);
        $urls = $this->indexExtractor->extractCandidateListingUrls($listingPageUrl, $html, $max);
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
}
