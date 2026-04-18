<?php

namespace App\Services\Scraping;

/**
 * Picks a single URL from an HTML srcset attribute (prefer largest logical resolution).
 */
final class SrcsetParser
{
    /**
     * @param  string  $srcset  Raw srcset attribute value
     * @param  string  $baseUrl  Page URL for resolving relative candidates
     */
    public static function pickLargestAbsoluteUrl(string $srcset, string $baseUrl): ?string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return null;
        }

        $bestUrl = null;
        $bestScore = -1;

        foreach (explode(',', $srcset) as $piece) {
            $piece = trim($piece);
            if ($piece === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $piece);
            $urlPart = $parts[0] ?? '';
            if ($urlPart === '' || str_starts_with(strtolower($urlPart), 'data:')) {
                continue;
            }

            $descriptor = '';
            if (isset($parts[1])) {
                $descriptor = strtolower(trim((string) $parts[1]));
                for ($i = 2; $i < count($parts); $i++) {
                    $descriptor .= ' '.strtolower(trim((string) $parts[$i]));
                }
            }

            $absolute = self::toAbsolute($baseUrl, $urlPart);
            $score = self::descriptorScore($descriptor);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUrl = $absolute;
            }
        }

        return $bestUrl;
    }

    private static function descriptorScore(string $descriptor): int
    {
        $descriptor = trim($descriptor);
        if ($descriptor === '') {
            return 1;
        }

        if (preg_match('/(\d+)w\b/i', $descriptor, $m) === 1) {
            return max(1, (int) $m[1]);
        }

        if (preg_match('/([\d.]+)x\b/i', $descriptor, $m) === 1) {
            return (int) round((float) $m[1] * 1000);
        }

        return 1;
    }

    private static function toAbsolute(string $base, string $href): string
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
}
