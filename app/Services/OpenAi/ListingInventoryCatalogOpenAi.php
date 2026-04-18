<?php

namespace App\Services\OpenAi;

use App\Services\Scraping\JsonLdScriptExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uses Chat Completions to derive listing/detail page URLs from inventory HTML + JSON-LD blocks.
 */
class ListingInventoryCatalogOpenAi
{
    public function isConfigured(): bool
    {
        $key = config('openai.api_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @return list<string> Absolute https listing URLs (caller filters by domain/path policy).
     */
    public function extractListingUrlsFromInventoryHtml(string $inventoryUrl, string $html, int $maxListings): array
    {
        $blocks = JsonLdScriptExtractor::extractRawBlocks($html);

        $maxHtml = max(8_000, (int) config('openai.listing_inventory_catalog_max_html_chars'));
        $maxLd = max(4_000, (int) config('openai.listing_inventory_catalog_max_json_ld_chars'));

        $jsonLdSection = $this->buildJsonLdSection($blocks, $maxLd);
        $htmlSection = mb_substr($html, 0, $maxHtml, 'UTF-8');
        if ($htmlSection === '' && $html !== '') {
            $htmlSection = mb_substr($html, 0, $maxHtml);
        }

        $parts = parse_url($inventoryUrl);
        $host = ($parts !== false && isset($parts['host'])) ? strtolower((string) $parts['host']) : '';

        $instructions = trim(view('prompts.openai.listing-inventory-catalog', [
            'inventoryUrl' => $inventoryUrl,
            'listingHost' => $host,
            'jsonLdSection' => $jsonLdSection !== '' ? $jsonLdSection : '(none — no application/ld+json blocks found in HTML)',
            'htmlSection' => $htmlSection !== '' ? $htmlSection : '(empty)',
        ])->render());

        $model = (string) config('openai.listing_inventory_catalog_model');

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'user', 'content' => $instructions],
            ],
            'response_format' => [
                'type' => 'json_object',
            ],
            'max_completion_tokens' => 8192,
        ];

        Log::debug('OpenAI inventory catalog request', [
            'inventory_url' => $inventoryUrl,
            'model' => $model,
            'json_ld_block_count' => count($blocks),
            'html_chars' => strlen($html),
        ]);

        $response = Http::withToken((string) config('openai.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout(min(120, (int) config('facebook_marketplace.scraper_timeout_seconds') + 90))
            ->post(rtrim((string) config('openai.base_url'), '/').'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'OpenAI chat/completions HTTP '.$response->status().': '.$response->body(),
            );
        }

        /** @var array<string, mixed> $data */
        $data = $response->json();

        $text = data_get($data, 'choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            throw new \RuntimeException('OpenAI inventory catalog returned empty message content.');
        }

        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OpenAI inventory catalog JSON decode failed.');
        }

        /** @var mixed $urlsRaw */
        $urlsRaw = $decoded['listing_urls'] ?? null;
        if (! is_array($urlsRaw)) {
            return [];
        }

        /** @var list<string> $out */
        $out = [];
        foreach ($urlsRaw as $u) {
            if (! is_string($u)) {
                continue;
            }
            $u = trim($u);
            if ($u === '' || filter_var($u, FILTER_VALIDATE_URL) === false) {
                continue;
            }
            if (! str_starts_with(strtolower($u), 'http')) {
                continue;
            }
            $out[] = $u;
        }

        $out = array_values(array_unique($out));
        if (count($out) > $maxListings) {
            $out = array_slice($out, 0, $maxListings);
        }

        return $out;
    }

    /**
     * @param  list<string>  $blocks
     */
    private function buildJsonLdSection(array $blocks, int $maxChars): string
    {
        if ($blocks === []) {
            return '';
        }

        $buf = '';
        foreach ($blocks as $i => $block) {
            $label = '--- Block '.($i + 1)." ---\n";
            $need = strlen($label) + strlen($block);
            if (strlen($buf) + $need > $maxChars) {
                $remaining = $maxChars - strlen($buf) - strlen($label);
                if ($remaining > 80) {
                    $buf .= $label.mb_substr($block, 0, max(0, $remaining - 20), 'UTF-8')."\n…(truncated)\n";
                }
                break;
            }
            $buf .= $label.$block."\n\n";
        }

        return trim($buf);
    }
}
