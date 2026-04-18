<?php

namespace App\Services\Scraping;

/**
 * Extracts raw JSON text from {@code <script type="application/ld+json">} blocks.
 */
final class JsonLdScriptExtractor
{
    /**
     * @return list<string> Non-empty JSON payloads (may be invalid JSON if the page is broken).
     */
    public static function extractRawBlocks(string $html): array
    {
        $out = [];
        if (preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches) > 0) {
            foreach ($matches[1] as $raw) {
                $t = trim((string) $raw);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }

        return $out;
    }
}
