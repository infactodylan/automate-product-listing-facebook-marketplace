<?php

namespace App\Services\OpenAi;

use App\Services\Scraping\ListingIndexExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingIndexOpenAiDiscoverer
{
    public function __construct(
        private OpenAiResponsesClient $client,
        private ListingIndexExtractor $indexExtractor,
    ) {}

    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * Uses OpenAI web_search (domain-filtered); results are intersected with URLs
     * extracted from the user's HTML page so only on-page listing links are kept.
     *
     * @return list<string>
     */
    public function discoverListingUrls(string $listingPageUrl, int $maxListings): array
    {
        $parts = parse_url($listingPageUrl);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return [];
        }

        $domain = strtolower((string) $parts['host']);

        $tools = [
            [
                'type' => 'web_search',
                'filters' => [
                    'allowed_domains' => [$domain],
                ],
                'external_web_access' => true,
            ],
        ];

        $model = (string) config('openai.listing_index_model');
        $effort = (string) config('openai.listing_index_reasoning_effort');

        $input = [[
            'role' => 'user',
            'content' => $this->buildInstructions($listingPageUrl, $maxListings),
        ]];

        $extraPayload = [
            'include' => ['web_search_call.action.sources'],
        ];

        $reasoning = ['effort' => $effort];

        $noopExecutor = static function (string $name, string $argumentsJson, string $callId): string {
            return json_encode([
                'ok' => false,
                'error' => 'Use only built-in web_search.',
                'received' => ['name' => $name, 'call_id' => $callId],
            ]) ?: '{"ok":false}';
        };

        Log::info('OpenAI listing index discoverer: starting Responses run', [
            'inventory_url' => $listingPageUrl,
            'max_listings' => $maxListings,
            'model' => $model,
            'domain' => $domain,
        ]);

        $final = $this->client->runUntilIdle(
            $model,
            $input,
            $tools,
            $reasoning,
            $noopExecutor,
            (int) config('openai.max_tool_rounds'),
            $extraPayload,
        );

        $gathered = OpenAiResponseUrlParser::allHttpUrls($final);

        $filtered = $this->indexExtractor->filterListingUrls($listingPageUrl, $gathered, $maxListings);

        $filtered = $this->restrictToUrlsLinkedOnUserPage($listingPageUrl, $filtered, $maxListings);

        Log::info('OpenAI listing index discoverer: URLs extracted', [
            'inventory_url' => $listingPageUrl,
            'urls_from_response' => count($gathered),
            'urls_after_domain_and_page_filter' => count($filtered),
        ]);

        return $filtered;
    }

    /**
     * Keep only URLs that appear as same-site listing links on the exact HTML document
     * at {@see $listingPageUrl}. This prevents web_search from returning unrelated paths
     * elsewhere on the domain.
     *
     * @param  list<string>  $openAiUrls
     * @return list<string>
     */
    private function restrictToUrlsLinkedOnUserPage(string $listingPageUrl, array $openAiUrls, int $maxListings): array
    {
        if ($openAiUrls === []) {
            return [];
        }

        $response = Http::withHeaders([
            'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
        ])
            ->timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->get($listingPageUrl);

        if (! $response->successful()) {
            Log::warning('OpenAI listing index discoverer: user page fetch failed; dropping OpenAI URLs', [
                'inventory_url' => $listingPageUrl,
                'http_status' => $response->status(),
            ]);

            return [];
        }

        $cap = max(500, $maxListings * 50);
        $pageUrls = $this->indexExtractor->extractCandidateListingUrls($listingPageUrl, $response->body(), $cap);

        $openSet = array_fill_keys($openAiUrls, true);

        /** @var list<string> $ordered */
        $ordered = [];
        foreach ($pageUrls as $u) {
            if (isset($openSet[$u]) && count($ordered) < $maxListings) {
                $ordered[] = $u;
            }
        }

        return $ordered;
    }

    private function buildInstructions(string $listingPageUrl, int $maxListings): string
    {
        return trim(view('prompts.openai.listing-index-discoverer', [
            'listingPageUrl' => $listingPageUrl,
            'maxListings' => $maxListings,
        ])->render());
    }
}
