<?php

namespace App\Services\OpenAi;

/**
 * Extracts http(s) URLs from OpenAI Responses API JSON payloads.
 */
class OpenAiResponseUrlParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function allHttpUrls(array $payload): array
    {
        $urls = [];

        $haystack = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if (! is_string($haystack)) {
            $haystack = '';
        }

        $haystack = str_replace('\\/', '/', $haystack);

        if ($haystack !== '' && preg_match_all('#https?://[^\s"\']+#i', $haystack, $matches) > 0) {
            foreach ($matches[0] as $raw) {
                $urls[] = self::sanitizeLooseUrl((string) $raw);
            }
        }

        $walker = function (mixed $node) use (&$walker, &$urls): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if (($key === 'url' || $key === 'source_url') && is_string($value) && str_starts_with($value, 'http')) {
                    $urls[] = self::sanitizeLooseUrl($value);
                }
                $walker($value);
            }
        };

        $walker($payload);

        /** @var list<string> */
        $stringUrls = [];
        foreach ($urls as $u) {
            if (is_string($u) && $u !== '') {
                $stringUrls[] = self::sanitizeLooseUrl($u);
            }
        }

        return array_values(array_unique(array_filter($stringUrls, fn (string $u): bool => $u !== '')));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public static function imageHttpUrls(array $payload): array
    {
        $all = self::allHttpUrls($payload);

        return array_values(array_filter($all, function (string $u): bool {
            return preg_match('#\.(jpe?g|png|gif|webp|bmp)(\?[^\s]*)?$#i', $u) === 1;
        }));
    }

    private static function sanitizeLooseUrl(string $url): string
    {
        $url = trim($url);

        return rtrim($url, '.,;)"\'\\');
    }
}
