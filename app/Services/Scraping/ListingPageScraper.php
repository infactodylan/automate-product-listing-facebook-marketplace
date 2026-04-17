<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;

class ListingPageScraper
{
    /**
     * @return array{
     *   title:string,
     *   price_usd:int|null,
     *   description:string,
     *   condition_raw:string|null,
     *   category:string|null,
     *   image_urls:list<string>,
     *   source_url:string
     * }
     */
    public function scrape(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
            'Accept' => 'text/html,application/xhtml+xml',
        ])
            ->timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Listing page returned HTTP '.$response->status().'.');
        }

        $html = $response->body();
        $jsonLd = $this->extractJsonLdBlocks($html);
        $product = $this->firstProductJsonLd($jsonLd);

        $title = $product['name'] ?? $this->metaContent($html, 'og:title')
            ?: $this->titleTag($html)
            ?: 'Listing';

        $description = $product['description'] ?? $this->metaContent($html, 'og:description')
            ?: $this->metaContent($html, 'description')
            ?: '';

        $priceUsd = null;
        if (isset($product['offers'])) {
            $offers = $product['offers'];
            if (isset($offers['price'])) {
                $priceUsd = $this->normalizeUsdPrice($offers['price']);
            } elseif (is_array($offers) && isset($offers[0]['price'])) {
                $priceUsd = $this->normalizeUsdPrice($offers[0]['price']);
            }
        }
        $priceUsd ??= $this->guessPriceFromHtml($html);

        $images = [];
        if (isset($product['image'])) {
            $images = $this->normalizeImageUrls($product['image'], $url);
        }
        if ($images === []) {
            $og = $this->metaContent($html, 'og:image');
            if (is_string($og) && $og !== '') {
                $images = [$this->toAbsoluteUrl($url, $og)];
            }
        }

        $conditionRaw = $product['itemCondition'] ?? null;
        if (is_string($conditionRaw)) {
            $conditionRaw = preg_replace('#^https?://schema\\.org/#i', '', $conditionRaw);
        }

        return [
            'title' => $this->truncate($title, 500),
            'price_usd' => $priceUsd,
            'description' => $this->truncate(trim((string) $description), 8000),
            'condition_raw' => is_string($conditionRaw) ? $conditionRaw : null,
            'category' => null,
            'image_urls' => array_values(array_unique(array_filter($images))),
            'source_url' => $url,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractJsonLdBlocks(string $html): array
    {
        $out = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $decoded = json_decode(trim(html_entity_decode($raw)), true);
                if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                    continue;
                }
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>|null
     */
    private function firstProductJsonLd(array $blocks): array
    {
        foreach ($blocks as $block) {
            if (($block['@type'] ?? null) === 'Product') {
                return $block;
            }
            if (($block['@graph'] ?? null) && is_array($block['@graph'])) {
                foreach ($block['@graph'] as $node) {
                    if (is_array($node) && ($node['@type'] ?? null) === 'Product') {
                        return $node;
                    }
                }
            }
            if (($block['@type'] ?? null) === 'ItemList' && isset($block['itemListElement']) && is_array($block['itemListElement'])) {
                foreach ($block['itemListElement'] as $el) {
                    if (is_array($el) && isset($el['item']) && is_array($el['item']) && ($el['item']['@type'] ?? null) === 'Product') {
                        return $el['item'];
                    }
                }
            }
        }

        return [];
    }

    private function metaContent(string $html, string $property): ?string
    {
        $patterns = [
            '#<meta[^>]+property=["\']'.preg_quote($property, '#').'["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+name=["\']'.preg_quote($property, '#').'["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']'.preg_quote($property, '#').'["\']#i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return null;
    }

    private function titleTag(string $html): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function guessPriceFromHtml(string $html): ?int
    {
        if (preg_match('/\\$\\s?([0-9]{1,6}(?:,[0-9]{3})*(?:\\.[0-9]{2})?)/', $html, $m) === 1) {
            return $this->normalizeUsdPrice(str_replace(',', '', $m[1]));
        }

        return null;
    }

    private function normalizeUsdPrice(mixed $price): ?int
    {
        if (is_int($price)) {
            return max(0, $price);
        }
        if (is_float($price)) {
            return max(0, (int) round($price));
        }
        if (is_string($price)) {
            $clean = preg_replace('/[^0-9.]/', '', $price) ?? '';
            if ($clean === '') {
                return null;
            }

            return max(0, (int) round((float) $clean));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function normalizeImageUrls(mixed $image, string $baseUrl): array
    {
        $urls = [];
        if (is_string($image)) {
            $urls[] = $this->toAbsoluteUrl($baseUrl, $image);
        } elseif (is_array($image)) {
            foreach ($image as $item) {
                if (is_string($item)) {
                    $urls[] = $this->toAbsoluteUrl($baseUrl, $item);
                } elseif (is_array($item) && isset($item['url']) && is_string($item['url'])) {
                    $urls[] = $this->toAbsoluteUrl($baseUrl, $item['url']);
                }
            }
        }

        return array_values(array_filter($urls));
    }

    private function toAbsoluteUrl(string $base, string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $b = parse_url($base);
        if ($b === false || ! isset($b['scheme'], $b['host'])) {
            return $href;
        }

        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($href, '//')) {
            return $b['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $basePath = isset($b['path']) ? preg_replace('#/[^/]*$#', '/', $b['path']) : '/';

        return $origin.$basePath.$href;
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
