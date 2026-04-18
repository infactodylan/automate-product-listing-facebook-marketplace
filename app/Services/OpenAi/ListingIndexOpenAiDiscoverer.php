<?php

namespace App\Services\OpenAi;

use App\Services\Scraping\ListingIndexExtractor;
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
     * Uses OpenAI web_search (domain-filtered) to discover listing detail URLs.
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

        Log::info('OpenAI listing index discoverer: URLs extracted', [
            'inventory_url' => $listingPageUrl,
            'urls_from_response' => count($gathered),
            'urls_after_domain_filter' => count($filtered),
        ]);

        return $filtered;
    }

    private function buildInstructions(string $listingPageUrl, int $maxListings): string
    {
        return trim(view('prompts.openai.listing-index-discoverer', [
            'listingPageUrl' => $listingPageUrl,
            'maxListings' => $maxListings,
        ])->render());
    }
}
