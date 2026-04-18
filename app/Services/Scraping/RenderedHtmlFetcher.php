<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches HTML for scraping, optionally via Browserless {@see https://docs.browserless.io/rest-apis/content}
 * so JavaScript-rendered pages return full DOM markup.
 */
final class RenderedHtmlFetcher
{
    public function shouldUseBrowserless(): bool
    {
        $b = config('facebook_marketplace.browserless');

        return ($b['enabled'] ?? false)
            && ($b['use_for_scraping'] ?? false)
            && is_string($b['token'] ?? null)
            && ($b['token'] ?? '') !== '';
    }

    /**
     * @throws \RuntimeException when the page cannot be retrieved
     */
    public function fetch(string $url): string
    {
        if ($this->shouldUseBrowserless()) {
            try {
                $html = $this->fetchViaBrowserless($url);
                if ($html !== '') {
                    Log::debug('Rendered HTML via Browserless', ['url' => $url]);

                    return $html;
                }
            } catch (\Throwable $e) {
                Log::warning('Browserless /content request failed', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! (config('facebook_marketplace.browserless.fallback_to_http') ?? true)) {
                throw new \RuntimeException(
                    'Browserless did not return HTML and HTTP fallback is disabled.',
                );
            }
        }

        return $this->fetchViaHttp($url);
    }

    /**
     * @throws \RuntimeException
     */
    private function fetchViaHttp(string $url): string
    {
        $response = Http::timeout((int) config('facebook_marketplace.scraper_timeout_seconds'))
            ->withHeaders([
                'User-Agent' => (string) config('facebook_marketplace.http_user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8',
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Listing page returned HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    /**
     * @throws \RuntimeException
     */
    private function fetchViaBrowserless(string $url): string
    {
        $b = config('facebook_marketplace.browserless');
        $base = rtrim((string) ($b['base_url'] ?? ''), '/');
        $token = (string) ($b['token'] ?? '');
        $timeout = max(15, (int) ($b['timeout_seconds'] ?? 90));

        $endpoint = $base.'/content?token='.rawurlencode($token);

        $payload = [
            'url' => $url,
        ];

        $ua = trim((string) config('facebook_marketplace.http_user_agent'));
        if ($ua !== '') {
            $payload['userAgent'] = $ua;
        }

        $waitMs = (int) ($b['wait_for_timeout_ms'] ?? 0);
        if ($waitMs > 0) {
            $payload['waitForTimeout'] = $waitMs;
        }

        $response = Http::timeout($timeout)
            ->withHeaders([
                'Accept' => 'text/html,application/json,*/*',
            ])
            ->asJson()
            ->post($endpoint, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'Browserless HTTP '.$response->status().': '.$response->body(),
            );
        }

        return $response->body();
    }
}
