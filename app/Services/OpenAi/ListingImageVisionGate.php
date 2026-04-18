<?php

namespace App\Services\OpenAi;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Filters scraped image URLs using a multimodal model before images are downloaded for export.
 */
class ListingImageVisionGate
{
    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $scrapedProducts
     * @return array<int, array<string, mixed>>
     */
    public function filterScrapedProducts(array $scrapedProducts): array
    {
        if (! config('openai.listing_image_vision_enabled') || ! $this->isConfigured()) {
            return $scrapedProducts;
        }

        $out = [];

        foreach ($scrapedProducts as $product) {
            $urls = isset($product['image_urls']) && is_array($product['image_urls'])
                ? array_values(array_filter(array_map('strval', $product['image_urls'])))
                : [];

            if ($urls === []) {
                $out[] = $product;

                continue;
            }

            $maxCand = (int) config('openai.listing_image_vision_max_candidates');
            $candidateUrls = array_slice($urls, 0, max(1, $maxCand));

            try {
                $decisions = $this->evaluateListingImages($product, $candidateUrls);
            } catch (\Throwable $e) {
                Log::warning('Listing image vision gate failed; keeping scraped URLs unchanged', [
                    'error' => $e->getMessage(),
                    'listing_title' => (string) ($product['title'] ?? ''),
                ]);
                $out[] = $product;

                continue;
            }

            $kept = [];
            foreach ($decisions as $row) {
                if (! empty($row['keep']) && isset($row['url']) && is_string($row['url'])) {
                    $kept[] = $row['url'];
                }
            }

            $maxPer = (int) config('facebook_marketplace.max_images_per_listing');
            if ($maxPer > 0 && count($kept) > $maxPer) {
                $kept = array_slice($kept, 0, $maxPer);
            }

            $product['image_urls'] = $kept;
            $product['image_vision_manifest'] = $decisions;
            $out[] = $product;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  list<string>  $imageUrls
     * @return list<array{url: string, keep: bool, reason: string}>
     */
    private function evaluateListingImages(array $product, array $imageUrls): array
    {
        $title = (string) ($product['title'] ?? 'Listing');
        $description = (string) ($product['description'] ?? '');
        $sourceUrl = (string) ($product['source_url'] ?? '');
        $excerpt = mb_substr(trim($description), 0, 1200);

        $instructions = trim(view('prompts.openai.listing-image-vision-gate', [
            'title' => $title,
            'descriptionExcerpt' => $excerpt,
            'sourceUrl' => $sourceUrl,
            'imageUrls' => $imageUrls,
        ])->render());

        $debugSession = OpenAiDebugArtifactSession::start(
            'listing-image-vision-gate',
            ($sourceUrl !== '' ? $sourceUrl : $title).'|'.$title,
        );

        /** @var list<array{type: string, text?: string, image_url?: array<string, mixed>}> $content */
        $content = [['type' => 'text', 'text' => $instructions]];

        foreach ($imageUrls as $u) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $u,
                    'detail' => 'low',
                ],
            ];
        }

        $payload = [
            'model' => (string) config('openai.listing_image_vision_model'),
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'response_format' => [
                'type' => 'json_object',
            ],
            'max_completion_tokens' => 4096,
        ];

        if ($debugSession !== null) {
            $debugSession->writeText('instructions.txt', $instructions);
            $debugSession->saveHttpUrlsAsFiles($imageUrls, 'candidate');
            $debugSession->writeJson('chat-completions-request.json', $payload);
        }

        Log::debug('Listing image vision gate request', [
            'model' => $payload['model'],
            'image_count' => count($imageUrls),
            'listing_title' => $title,
            'debug_artifacts_path' => $debugSession?->basePath(),
        ]);

        $response = Http::withToken((string) config('openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(min(600, (int) config('facebook_marketplace.scraper_timeout_seconds') + 180))
            ->post(rtrim((string) config('openai.base_url'), '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'OpenAI chat/completions HTTP '.$response->status().': '.$response->body(),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        if ($debugSession !== null) {
            $debugSession->writeJson('chat-completions-response.json', $data);
        }

        $text = data_get($data, 'choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new \RuntimeException('OpenAI chat/completions returned empty message content.');
        }

        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Vision gate JSON decode failed.');
        }

        /** @var mixed $resultsRaw */
        $resultsRaw = $decoded['results'] ?? null;
        if (! is_array($resultsRaw)) {
            throw new \RuntimeException('Vision gate JSON missing results array.');
        }

        /** @var array<string, true> $urlSet */
        $urlSet = array_fill_keys($imageUrls, true);

        /** @var list<array{url: string, keep: bool, reason: string}> $normalized */
        $normalized = [];

        foreach ($resultsRaw as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = isset($row['url']) && is_string($row['url']) ? trim($row['url']) : '';
            if ($url === '' || ! isset($urlSet[$url])) {
                continue;
            }

            $keep = filter_var($row['keep'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $reason = isset($row['reason']) && is_string($row['reason']) ? trim($row['reason']) : '';

            $normalized[] = [
                'url' => $url,
                'keep' => $keep,
                'reason' => $reason !== '' ? $reason : ($keep ? 'Approved.' : 'Rejected.'),
            ];
        }

        foreach ($imageUrls as $u) {
            $found = false;
            foreach ($normalized as $n) {
                if ($n['url'] === $u) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $normalized[] = [
                    'url' => $u,
                    'keep' => true,
                    'reason' => 'No explicit decision returned; kept by default.',
                ];
            }
        }

        if ($debugSession !== null) {
            $debugSession->writeJson('parsed-decisions.json', ['results' => $normalized]);
        }

        return $normalized;
    }
}
