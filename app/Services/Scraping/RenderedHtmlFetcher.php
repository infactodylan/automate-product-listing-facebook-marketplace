<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;

/**
 * Fetches listing and inventory HTML via HTTP GET (server response body).
 * Used for index discovery (OpenAI + JSON-LD), detail scraping, and link extraction.
 */
final class RenderedHtmlFetcher
{
    /**
     * @throws \RuntimeException when the page cannot be retrieved
     */
    public function fetch(string $url): string
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
}
