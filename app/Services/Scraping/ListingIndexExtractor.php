<?php

namespace App\Services\Scraping;

class ListingIndexExtractor
{
    /**
     * @return list<string> Absolute HTTP(S) listing URLs, de-duplicated, stable order.
     */
    public function extractCandidateListingUrls(string $indexUrl, string $html, int $maxListings): array
    {
        $indexParts = parse_url($indexUrl);
        if ($indexParts === false || ! isset($indexParts['scheme'], $indexParts['host'])) {
            return [];
        }

        $baseHost = strtolower((string) $indexParts['host']);

        $html = $this->decodeHtmlEntitiesToUtf8($html);

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $found = [];
        $anchors = $dom->getElementsByTagName('a');
        for ($i = 0; $i < $anchors->length; $i++) {
            $a = $anchors->item($i);
            if (! $a instanceof \DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }

            $absolute = $this->toAbsoluteUrl($indexUrl, $href);
            if ($absolute === null) {
                continue;
            }

            $p = parse_url($absolute);
            if ($p === false || ! isset($p['host'], $p['scheme'])) {
                continue;
            }

            if (strtolower((string) $p['host']) !== $baseHost) {
                continue;
            }

            if (strtolower((string) $p['scheme']) !== 'http' && strtolower((string) $p['scheme']) !== 'https') {
                continue;
            }

            if (! $this->looksLikeListingPath($p['path'] ?? '/')) {
                continue;
            }

            $found[] = $this->stripFragment($absolute);
        }

        $found = array_values(array_unique($found));
        if ($found === []) {
            if ($this->looksLikeListingPath((string) (parse_url($indexUrl)['path'] ?? '')) || $this->htmlMentionsJsonLdProduct($html)) {
                $found[] = $this->stripFragment($indexUrl);
            }
        }

        if (count($found) > $maxListings) {
            $found = array_slice($found, 0, $maxListings);
        }

        return $found;
    }

    private function stripFragment(string $url): string
    {
        $p = parse_url($url);
        if ($p === false) {
            return $url;
        }
        $p['fragment'] = null;

        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? '';
        $port = isset($p['port']) ? ':'.$p['port'] : '';
        $user = $p['user'] ?? '';
        $pass = isset($p['pass']) ? ':'.$p['pass'] : '';
        $userInfo = $user !== '' || $pass !== '' ? $user.$pass.'@' : '';
        $path = $p['path'] ?? '/';
        $query = isset($p['query']) ? '?'.$p['query'] : '';

        return $scheme.'://'.$userInfo.$host.$port.$path.$query;
    }

    private function toAbsoluteUrl(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $b = parse_url($base);
        if ($b === false || ! isset($b['scheme'], $b['host'])) {
            return null;
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

    private function looksLikeListingPath(string $path): bool
    {
        $lower = strtolower($path);
        if ($lower === '/' || $lower === '') {
            return false;
        }

        foreach (['/inventory', '/listing', '/vehicle', '/vehicles', '/cars', '/product', '/products', '/used-', '/new-', '/detail', '/item'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        if (preg_match('#/(\\d{4,})-#', $lower) === 1) {
            return true;
        }

        return preg_match('#/(\\d{5,})#', $lower) === 1;
    }

    private function htmlMentionsJsonLdProduct(string $html): bool
    {
        return str_contains($html, '"@type":"Product"')
            || str_contains($html, '"@type": "Product"')
            || str_contains($html, 'application/ld+json');
    }

    private function decodeHtmlEntitiesToUtf8(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
