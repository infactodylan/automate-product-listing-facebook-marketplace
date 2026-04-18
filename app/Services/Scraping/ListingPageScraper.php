<?php

namespace App\Services\Scraping;

use App\Services\OpenAi\ListingPageImagesOpenAiDiscoverer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ListingPageScraper
{
    private const JSON_LD_IMAGE_DEPTH_MAX = 14;

    /** @see extractDomProductImageUrls() */
    private const PRODUCT_GALLERY_ANCESTOR_PATTERN = '/(gallery|carousel|swiper|inventory|vehicle|vdp|listing\-photo|detail\-photo|photo\-gallery|image\-gallery|vehicle\-gallery|inventory\-detail|listing\-detail|slideshow|lightbox|media\-gallery|vehicles\-gallery|stock\-photos|product\-media|vehicle\-photos|photos\-wrapper|inventory\-photos|listing\-gallery)/i';

    private const INVENTORY_IMAGE_PATH_PATTERN = '#/(\d{4}|vin|vehicle|inventory|listing|stock|vdp|detail|photo|photos|vehicles)(/|$)#i';

    public function __construct(
        private ListingPageImagesOpenAiDiscoverer $openAiListingImages,
    ) {}

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
    public function scrape(string $url, ?int $listingExportId = null): array
    {
        $this->logScrapePhase('fetch_html', $url, $listingExportId, []);

        $response = Http::timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            $this->logScrapePhase('fetch_html_failed', $url, $listingExportId, ['http_status' => $response->status()]);
            throw new \RuntimeException('Listing page returned HTTP '.$response->status().'.');
        }

        $this->logScrapePhase('fetch_html_ok', $url, $listingExportId, ['bytes' => strlen($response->body())]);

        $html = $response->body();
        $jsonLdBlocks = $this->extractJsonLdBlocks($html);
        $this->logScrapePhase('json_ld_blocks', $url, $listingExportId, ['count' => count($jsonLdBlocks)]);

        $product = $this->firstProductOrVehicleJsonLd($jsonLdBlocks);

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

        $productTypes = $product['@type'] ?? null;
        $this->logScrapePhase('product_json_ld', $url, $listingExportId, [
            'found' => $product !== [],
            'types' => is_array($productTypes) ? $productTypes : (is_string($productTypes) ? [$productTypes] : $productTypes),
        ]);

        $images = [];

        $fromProductNode = $this->extractImagesFromJsonNode($product, $url, 0);
        $images = array_merge($images, $fromProductNode);
        $this->logScrapePhase('images_json_ld_product_subtree', $url, $listingExportId, ['count' => count($fromProductNode)]);

        $fromMeta = $this->extractMetaImages($html, $url);
        $images = array_merge($images, $fromMeta);
        $this->logScrapePhase('images_meta_og_twitter', $url, $listingExportId, ['count' => count($fromMeta)]);

        $fromDom = $this->extractDomProductImageUrls($html, $url);
        $images = array_merge($images, $fromDom);
        $this->logScrapePhase('images_dom_product_gallery', $url, $listingExportId, ['count' => count($fromDom)]);

        if ($images === []) {
            $fromRegex = $this->extractRegexImageCandidates($html);
            $images = array_merge($images, $fromRegex);
            $this->logScrapePhase('images_regex_fallback', $url, $listingExportId, [
                'count' => count($fromRegex),
                'note' => 'No product-sourced images yet; using URL regex as last resort before OpenAI.',
            ]);
        }

        $beforeFilterCount = count($images);
        $images = array_values(array_unique(array_filter(array_map(function (string $u) use ($url): ?string {
            $u = trim($u);
            if ($u === '' || $this->shouldExcludeNoiseImageUrl($u)) {
                return null;
            }
            $absolute = preg_match('#^https?://#i', $u) === 1 ? $u : $this->toAbsoluteUrl($url, $u);
            if ($this->shouldExcludeNoiseImageUrl($absolute)) {
                return null;
            }

            return $absolute;
        }, $images))));
        $this->logScrapePhase('images_after_normalize_and_noise_filter', $url, $listingExportId, [
            'before_filter' => $beforeFilterCount,
            'after' => count($images),
        ]);

        if ($images === [] && config('openai.listing_detail_images_openai_enabled')
            && $this->openAiListingImages->isConfigured()) {
            $this->logScrapePhase('openai_listing_images_start', $url, $listingExportId, []);
            try {
                $fromOpenAi = $this->openAiListingImages->discoverImageUrls($url);
                $added = 0;
                foreach ($fromOpenAi as $imgUrl) {
                    $imgUrl = trim($imgUrl);
                    if ($imgUrl === '' || $this->shouldExcludeNoiseImageUrl($imgUrl)) {
                        continue;
                    }
                    $images[] = $imgUrl;
                    $added++;
                }
                $images = array_values(array_unique($images));
                $this->logScrapePhase('openai_listing_images_done', $url, $listingExportId, [
                    'raw_from_openai' => count($fromOpenAi),
                    'added_after_noise_filter' => $added,
                    'total_urls' => count($images),
                ]);
            } catch (\Throwable $e) {
                Log::warning('OpenAI listing image discovery failed', [
                    'url' => $url,
                    'listing_export_id' => $listingExportId,
                    'error' => $e->getMessage(),
                ]);
            }
        } elseif ($images === []) {
            $this->logScrapePhase('openai_listing_images_skipped', $url, $listingExportId, [
                'reason' => ! config('openai.listing_detail_images_openai_enabled')
                    ? 'listing_detail_images_openai_enabled is false'
                    : 'OpenAI API key not configured',
            ]);
        }

        $this->logScrapePhase('scrape_complete', $url, $listingExportId, [
            'image_url_count' => count($images),
            'title_length' => mb_strlen((string) $title),
        ]);

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
            'image_urls' => $images,
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
     * @return array<string, mixed>
     */
    private function firstProductOrVehicleJsonLd(array $blocks): array
    {
        foreach ($blocks as $block) {
            if ($this->jsonLdTypeIncludes($block['@type'] ?? null, 'Product')) {
                return $block;
            }
            if (($block['@graph'] ?? null) && is_array($block['@graph'])) {
                foreach ($block['@graph'] as $node) {
                    if (is_array($node) && $this->jsonLdTypeIncludes($node['@type'] ?? null, 'Product')) {
                        return $node;
                    }
                }
            }
            if (($block['@type'] ?? null) === 'ItemList' && isset($block['itemListElement']) && is_array($block['itemListElement'])) {
                foreach ($block['itemListElement'] as $el) {
                    if (is_array($el) && isset($el['item']) && is_array($el['item']) && $this->jsonLdTypeIncludes($el['item']['@type'] ?? null, 'Product')) {
                        return $el['item'];
                    }
                }
            }
        }

        foreach ($blocks as $block) {
            if ($this->jsonLdTypeIncludes($block['@type'] ?? null, 'Vehicle')) {
                return $block;
            }
            if (($block['@graph'] ?? null) && is_array($block['@graph'])) {
                foreach ($block['@graph'] as $node) {
                    if (is_array($node) && $this->jsonLdTypeIncludes($node['@type'] ?? null, 'Vehicle')) {
                        return $node;
                    }
                }
            }
            if (($block['@type'] ?? null) === 'ItemList' && isset($block['itemListElement']) && is_array($block['itemListElement'])) {
                foreach ($block['itemListElement'] as $el) {
                    if (is_array($el) && isset($el['item']) && is_array($el['item']) && $this->jsonLdTypeIncludes($el['item']['@type'] ?? null, 'Vehicle')) {
                        return $el['item'];
                    }
                }
            }
        }

        return [];
    }

    private function jsonLdTypeIncludes(mixed $type, string $needle): bool
    {
        if ($type === null) {
            return false;
        }

        if (is_string($type)) {
            return strcasecmp($type, $needle) === 0;
        }

        if (is_array($type)) {
            foreach ($type as $t) {
                if (is_string($t) && strcasecmp($t, $needle) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function extractImagesFromJsonNode(array $node, string $baseUrl, int $depth): array
    {
        if ($depth > self::JSON_LD_IMAGE_DEPTH_MAX) {
            return [];
        }

        $out = [];

        if (($node['@type'] ?? null) === 'ImageObject') {
            foreach (['url', 'contentUrl', 'thumbnail'] as $k) {
                if (isset($node[$k])) {
                    $out = array_merge($out, $this->normalizeImageUrls($node[$k], $baseUrl));
                }
            }
        }

        foreach (['image', 'photo'] as $k) {
            if (isset($node[$k])) {
                $out = array_merge($out, $this->normalizeImageUrls($node[$k], $baseUrl));
            }
        }

        if (isset($node['thumbnailUrl'])) {
            $out = array_merge($out, $this->normalizeImageUrls($node['thumbnailUrl'], $baseUrl));
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                /** @var array<string, mixed> $child */
                $out = array_merge($out, $this->extractImagesFromJsonNode($child, $baseUrl, $depth + 1));
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function extractMetaImages(string $html, string $baseUrl): array
    {
        $urls = [];

        $patterns = [
            '#<meta[^>]+property=["\']og:image(?::url|(?:\:[a-z]+))?["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::url|(?:\:[a-z]+))?["\']#i',
            '#<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i',
            '#<meta[^>]+property=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']#i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches) > 0) {
                foreach ($matches[1] as $raw) {
                    $urls[] = $this->toAbsoluteUrl($baseUrl, html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            }
        }

        return $urls;
    }

    /**
     * Only collects &lt;img&gt; candidates that appear to belong to the listing (gallery/product
     * containers or inventory-like URL paths), and skips header/nav/footer chrome.
     *
     * @return list<string>
     */
    private function extractDomProductImageUrls(string $html, string $baseUrl): array
    {
        $urls = [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $imgs = $dom->getElementsByTagName('img');
        for ($i = 0; $i < $imgs->length; $i++) {
            $img = $imgs->item($i);
            if (! $img instanceof \DOMElement) {
                continue;
            }

            if ($this->domImgIsInSiteChrome($img)) {
                continue;
            }

            foreach (['src', 'data-src', 'data-lazy-src', 'data-original', 'data-image'] as $attr) {
                $href = $img->getAttribute($attr);
                if ($href === '' || str_starts_with(strtolower($href), 'data:')) {
                    continue;
                }
                $absolute = $this->toAbsoluteUrl($baseUrl, $href);
                if ($this->shouldExcludeNoiseImageUrl($absolute)) {
                    continue;
                }
                if ($this->domImgMatchesProductContext($img, $absolute)) {
                    $urls[] = $absolute;
                }
            }

            $srcset = $img->getAttribute('srcset');
            if ($srcset !== '') {
                foreach (explode(',', $srcset) as $piece) {
                    $piece = trim($piece);
                    if ($piece === '') {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $piece);
                    $first = $parts[0] ?? '';
                    if ($first === '' || str_starts_with(strtolower($first), 'data:')) {
                        continue;
                    }
                    $absolute = $this->toAbsoluteUrl($baseUrl, $first);
                    if ($this->shouldExcludeNoiseImageUrl($absolute)) {
                        continue;
                    }
                    if ($this->domImgMatchesProductContext($img, $absolute)) {
                        $urls[] = $absolute;
                    }
                }
            }
        }

        return $urls;
    }

    private function domImgIsInSiteChrome(\DOMElement $img): bool
    {
        for ($node = $img->parentNode; $node; $node = $node->parentNode) {
            if ($node instanceof \DOMElement) {
                $tag = strtolower($node->tagName);
                if ($tag === 'nav' || $tag === 'header' || $tag === 'footer') {
                    return true;
                }
            }
        }

        return false;
    }

    private function domImgMatchesProductContext(\DOMElement $img, string $absoluteUrl): bool
    {
        if ($this->galleryOrProductAncestorMatches($img)) {
            return true;
        }

        return $this->absoluteUrlLooksLikeInventoryPhotoPath($absoluteUrl);
    }

    private function galleryOrProductAncestorMatches(\DOMElement $img): bool
    {
        for ($node = $img->parentNode, $depth = 0; $node && $depth < 24; $node = $node->parentNode, $depth++) {
            if ($node instanceof \DOMElement) {
                $haystack = $node->getAttribute('class').' '.$node->getAttribute('id').' '
                    .$node->getAttribute('data-component').' '.$node->getAttribute('aria-label');
                if (trim($haystack) !== '' && preg_match(self::PRODUCT_GALLERY_ANCESTOR_PATTERN, $haystack) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function absoluteUrlLooksLikeInventoryPhotoPath(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '') {
            return false;
        }

        if (preg_match(self::INVENTORY_IMAGE_PATH_PATTERN, $path) !== 1) {
            return false;
        }

        return ! $this->shouldExcludeNoiseImageUrl($url);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logScrapePhase(string $phase, string $listingUrl, ?int $listingExportId, array $context): void
    {
        Log::info('Listing page scrape', array_merge([
            'phase' => $phase,
            'listing_url' => $listingUrl,
            'listing_export_id' => $listingExportId,
        ], $context));
    }

    /**
     * @return list<string>
     */
    private function extractRegexImageCandidates(string $html): array
    {
        $urls = [];
        if (preg_match_all('#https?://[^\s"\'<>]+\.(?:jpg|jpeg|png|gif|webp|bmp)(?:\?[^\s"\'<>]*)?#i', $html, $matches) > 0) {
            foreach ($matches[0] as $raw) {
                $urls[] = rtrim((string) $raw, '.,;)\'"');
            }
        }

        return $urls;
    }

    private function shouldExcludeNoiseImageUrl(string $url): bool
    {
        $lower = strtolower($url);

        $needles = [
            'skeleton', 'youtube', 'ribbon', 'favicon', 'placeholder', 'spinner',
            'youtube-play', 'fontawesome', 'font-awesome', 'pixel.gif', 'clear.gif',
            '1x1.gif', 'transparent.gif', 'blank.gif', 'vehicleSpecialRibbon',
            'skeleton-background', 'loading.gif', 'loading.svg', 'placeholder.png',
            '/icons/', '/icon/', 'logo-small', 'dealer-logo', '/avatar',
        ];

        foreach ($needles as $n) {
            if (str_contains($lower, $n)) {
                return true;
            }
        }

        return false;
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
