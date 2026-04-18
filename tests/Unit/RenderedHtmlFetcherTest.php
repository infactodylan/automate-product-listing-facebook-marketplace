<?php

namespace Tests\Unit;

use App\Services\Scraping\RenderedHtmlFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenderedHtmlFetcherTest extends TestCase
{
    public function test_it_fetches_via_http_when_browserless_disabled(): void
    {
        config([
            'facebook_marketplace.browserless.enabled' => false,
            'facebook_marketplace.browserless.use_for_scraping' => false,
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

    public function test_it_uses_browserless_when_configured_and_falls_back_on_failure(): void
    {
        config([
            'facebook_marketplace.browserless.enabled' => true,
            'facebook_marketplace.browserless.token' => 'tok-test',
            'facebook_marketplace.browserless.base_url' => 'https://production-sfo.browserless.io',
            'facebook_marketplace.browserless.use_for_scraping' => true,
            'facebook_marketplace.browserless.timeout_seconds' => 30,
            'facebook_marketplace.browserless.fallback_to_http' => true,
            'facebook_marketplace.scraper_timeout_seconds' => 10,
            'facebook_marketplace.http_user_agent' => 'TestUA/1.0',
        ]);

        Http::fake([
            'production-sfo.browserless.io/content*' => Http::response('', 502),
            'example.com/page' => Http::response('<html><body>fallback</body></html>', 200),
        ]);

        $html = app(RenderedHtmlFetcher::class)->fetch('https://example.com/page');

        $this->assertStringContainsString('fallback', $html);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'browserless.io/content')
                && str_contains($request->url(), 'token=tok-test')
                && $request->method() === 'POST';
        });
    }

    public function test_it_returns_browserless_body_when_successful(): void
    {
        config([
            'facebook_marketplace.browserless.enabled' => true,
            'facebook_marketplace.browserless.token' => 'tok-test',
            'facebook_marketplace.browserless.base_url' => 'https://production-sfo.browserless.io',
            'facebook_marketplace.browserless.use_for_scraping' => true,
            'facebook_marketplace.browserless.timeout_seconds' => 30,
            'facebook_marketplace.browserless.fallback_to_http' => false,
            'facebook_marketplace.http_user_agent' => 'TestUA/1.0',
        ]);

        Http::fake([
            'production-sfo.browserless.io/content*' => Http::response('<html><body>rendered</body></html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $html = app(RenderedHtmlFetcher::class)->fetch('https://example.com/page');

        $this->assertStringContainsString('rendered', $html);
        Http::assertSentCount(1);
    }
}
