<?php

namespace App\Services\OpenAi;

use App\Services\Scraping\ListingIndexExtractor;
use App\Services\Scraping\RenderedHtmlFetcher;
use Illuminate\Support\Facades\Log;

class ListingIndexOpenAiDiscoverer
{
    public function __construct(
        private OpenAiResponsesClient $client,
        private ListingIndexExtractor $indexExtractor,
        private RenderedHtmlFetcher $renderedHtml,
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

        $debugSession = OpenAiDebugArtifactSession::start('listing-index', $listingPageUrl);

        $final = $this->client->runUntilIdle(
            $model,
            $input,
            $tools,
            $reasoning,
            $noopExecutor,
            (int) config('openai.max_tool_rounds'),
            $extraPayload,
            $debugSession,
        );

        $gathered = OpenAiResponseUrlParser::allHttpUrls($final);

        $filtered = $this->indexExtractor->filterListingUrls($listingPageUrl, $gathered, $maxListings);

        $filtered = $this->restrictToUrlsLinkedOnUserPage($listingPageUrl, $filtered, $maxListings, $debugSession);

        if ($debugSession !== null) {
            $debugSession->writeJson('discoverer-urls-gathered.json', ['urls' => $gathered]);
            $debugSession->writeJson('discoverer-urls-final.json', ['urls' => $filtered]);
        }

        Log::debug('OpenAI listing index discoverer complete', [
            'inventory_url' => $listingPageUrl,
            'urls_from_response' => count($gathered),
            'urls_after_domain_and_page_filter' => count($filtered),
            'debug_artifacts_path' => $debugSession?->basePath(),
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
    private function restrictToUrlsLinkedOnUserPage(
        string $listingPageUrl,
        array $openAiUrls,
        int $maxListings,
        ?OpenAiDebugArtifactSession $debugSession = null,
    ): array {
        if ($openAiUrls === []) {
            return [];
        }

        try {
            $body = $this->renderedHtml->fetch($listingPageUrl);
        } catch (\Throwable $e) {
            Log::warning('OpenAI listing index discoverer: user page fetch failed; dropping OpenAI URLs', [
                'inventory_url' => $listingPageUrl,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if ($debugSession !== null) {
            if (strlen($body) <= 5_000_000) {
                $debugSession->writeText('same-site-filter-source.html', $body);
            } else {
                $debugSession->writeText('same-site-filter-source-skipped.txt', 'HTML omitted (>5MB).');
            }
        }

        $cap = max(500, $maxListings * 50);
        $pageUrls = $this->indexExtractor->extractCandidateListingUrls($listingPageUrl, $body, $cap);

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
