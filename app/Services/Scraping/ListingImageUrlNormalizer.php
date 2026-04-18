<?php

namespace App\Services\Scraping;

/**
 * Dedupes listing image URLs that differ only by CDN resize query params and merges tier-priority lists.
 */
final class ListingImageUrlNormalizer
{
    /** Query parameters removed when computing canonical identity for deduplication */
    private const RESIZE_QUERY_KEYS = [
        'w', 'width', 'h', 'height', 'q', 'quality', 'auto', 'fit', 'crop',
        'fm', 'format', 'dpr', 'resize', 'scale', 'size',
    ];

    /**
     * @param  list<array{url: string, tier: int}>  $candidates  Lower tier number = higher priority source
     * @return list<string> Ordered by tier (JSON-LD, DOM, meta, …) with one URL per canonical asset
     */
    public static function mergeTieredUnique(array $candidates): array
    {
        /** @var array<string, array{url: string, tier: int, score: int}> $winners keyed by canonical key */
        $winners = [];

        foreach ($candidates as $c) {
            $url = trim((string) ($c['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $tier = (int) ($c['tier'] ?? 99);
            $key = self::canonicalKey($url);
            if ($key === '') {
                continue;
            }

            $score = self::resolutionScoreFromUrl($url);

            if (! isset($winners[$key])) {
                $winners[$key] = ['url' => $url, 'tier' => $tier, 'score' => $score];

                continue;
            }

            $existing = $winners[$key];
            if ($tier < $existing['tier']) {
                $winners[$key] = ['url' => $url, 'tier' => $tier, 'score' => $score];
            } elseif ($tier === $existing['tier'] && $score > $existing['score']) {
                $winners[$key] = ['url' => $url, 'tier' => $tier, 'score' => $score];
            }
        }

        /** @var list<array{url: string, tier: int}> $decorated */
        $decorated = [];
        foreach ($winners as $w) {
            $decorated[] = ['url' => $w['url'], 'tier' => $w['tier']];
        }

        usort($decorated, function (array $a, array $b): int {
            if ($a['tier'] !== $b['tier']) {
                return $a['tier'] <=> $b['tier'];
            }

            return strcmp($a['url'], $b['url']);
        });

        $out = [];
        foreach ($decorated as $d) {
            $out[] = $d['url'];
        }

        return $out;
    }

    public static function canonicalKey(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return strtolower($url);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $query = (string) ($parts['query'] ?? '');

        parse_str($query, $q);
        if (! is_array($q)) {
            $q = [];
        }

        foreach (self::RESIZE_QUERY_KEYS as $k) {
            unset($q[$k], $q[ucfirst($k)]);
        }

        ksort($q);
        $newQuery = http_build_query($q);

        return $scheme.'://'.$host.$path.($newQuery !== '' ? '?'.$newQuery : '');
    }

    /**
     * Heuristic pixel count hint from URL (query or path segments like 800x600).
     */
    public static function resolutionScoreFromUrl(string $url): int
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return 0;
        }

        parse_str((string) ($parts['query'] ?? ''), $q);
        $w = isset($q['w']) ? (int) $q['w'] : (isset($q['width']) ? (int) $q['width'] : 0);
        $h = isset($q['h']) ? (int) $q['h'] : (isset($q['height']) ? (int) $q['height'] : 0);
        if ($w > 0 && $h > 0) {
            return $w * $h;
        }
        if ($w > 0) {
            return $w * $w;
        }
        if ($h > 0) {
            return $h * $h;
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#(\d{2,5})x(\d{2,5})#', $path, $m) === 1) {
            return (int) $m[1] * (int) $m[2];
        }

        return 0;
    }
}
