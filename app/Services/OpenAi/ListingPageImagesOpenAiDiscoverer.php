<?php

namespace App\Services\OpenAi;

use Illuminate\Support\Facades\Log;

/**
 * Uses OpenAI web_search to find vehicle/listing photo URLs when static HTML lacks them.
 * Search is not domain-filtered so CDN hosts (e.g. dealer CDNs) can appear in results.
 */
class ListingPageImagesOpenAiDiscoverer
{
    public function __construct(
        private OpenAiResponsesClient $client,
    ) {}

    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @return list<string>
     */
    public function discoverImageUrls(string $listingPageUrl): array
    {
        $tools = [
            [
                'type' => 'web_search',
                'external_web_access' => true,
            ],
        ];

        $model = (string) config('openai.listing_detail_images_model');
        $effort = (string) config('openai.listing_detail_images_reasoning_effort');

        $input = [[
            'role' => 'user',
            'content' => $this->buildInstructions($listingPageUrl),
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

        $debugSession = OpenAiDebugArtifactSession::start('listing-detail-images', $listingPageUrl);

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

        $urls = OpenAiResponseUrlParser::imageHttpUrls($final);
        $filtered = $this->filterNoise($urls);

        if ($debugSession !== null) {
            $debugSession->writeJson('discoverer-urls-raw.json', ['urls' => $urls]);
            $debugSession->writeJson('discoverer-urls-after-noise-filter.json', ['urls' => $filtered]);
        }

        Log::debug('OpenAI listing images discoverer complete', [
            'listing_page_url' => $listingPageUrl,
            'urls_from_response' => count($urls),
            'urls_after_noise_filter' => count($filtered),
            'debug_artifacts_path' => $debugSession?->basePath(),
        ]);

        return $filtered;
    }

    private function buildInstructions(string $listingPageUrl): string
    {
        return trim(view('prompts.openai.listing-page-images-discoverer', [
            'listingPageUrl' => $listingPageUrl,
        ])->render());
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function filterNoise(array $urls): array
    {
        $out = [];
        foreach ($urls as $u) {
            $lower = strtolower($u);
            foreach (['skeleton', 'youtube', 'ribbon', 'favicon', 'placeholder', 'youtube-play', 'vehicleSpecialRibbon', 'skeleton-background'] as $bad) {
                if (str_contains($lower, $bad)) {
                    continue 2;
                }
            }
            $out[] = $u;
        }

        return array_values(array_unique($out));
    }
}
