<?php

namespace Tests\Unit;

use App\Services\Scraping\RenderedHtmlFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenderedHtmlFetcherTest extends TestCase
{
    public function test_it_fetches_html_via_http_get(): void
    {
        config([
            'facebook_marketplace.scraper_timeout_seconds' => 10,
            'facebook_marketplace.http_user_agent' => 'TestUA/1.0',
        ]);

        Http::fake([
            'example.com/page' => Http::response('<html><body>ok</body></html>', 200),
        ]);

        $html = app(RenderedHtmlFetcher::class)->fetch('https://example.com/page');

        $this->assertStringContainsString('<body>ok</body>', $html);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://example.com/page'
                && $request->method() === 'GET';
        });
    }

    public function test_it_throws_when_http_response_is_not_successful(): void
    {
        config([
            'facebook_marketplace.scraper_timeout_seconds' => 10,
            'facebook_marketplace.http_user_agent' => 'TestUA/1.0',
        ]);

        Http::fake([
            'example.com/missing' => Http::response('Not Found', 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Listing page returned HTTP 404.');

        app(RenderedHtmlFetcher::class)->fetch('https://example.com/missing');
    }
}
