<?php

namespace Tests\Feature;

use App\Jobs\FetchListingIndexJob;
use App\Jobs\StartListingScrapeBatchJob;
use App\Models\ListingExport;
use App\Services\Scraping\ListingIndexDiscoveryService;
use App\Services\UrlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ListingExportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_builds_an_export_end_to_end_with_http_fixtures(): void
    {
        Storage::fake();

        $indexHtml = <<<'HTML'
            <html><body>
                <a href="/listings/2019-honda-civic">Vehicle</a>
            </body></html>
        HTML;

        $listingHtml = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@type": "Product",
                    "name": "2019 Honda Civic LX",
                    "description": "Low miles",
                    "image": "https://example.com/photo.jpg",
                    "offers": { "@type": "Offer", "price": "12995", "priceCurrency": "USD" }
                }
                </script>
            </head><body></body></html>
        HTML;

        Http::fake([
            'example.com/inventory' => Http::response($indexHtml, 200),
            'example.com/listings/*' => Http::response($listingHtml, 200),
            'example.com/photo.jpg' => Http::response(str_repeat('x', 32), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $token = bin2hex(random_bytes(32));

        $export = ListingExport::query()->create([
            'storage_key' => '00000000-0000-4000-8000-000000000001',
            'delivery_token_hash' => hash('sha256', $token),
            'listing_page_url' => 'https://example.com/inventory',
            'status' => ListingExport::STATUS_QUEUED,
        ]);

        (new FetchListingIndexJob($export->id))->handle(
            app(UrlSafetyValidator::class),
            app(ListingIndexDiscoveryService::class),
        );

        (new StartListingScrapeBatchJob($export->id))->handle();

        $export->refresh();

        $this->assertSame(ListingExport::STATUS_READY, $export->status);
        $this->assertNotNull($export->zip_relative_path);
        Storage::assertExists($export->zip_relative_path);

        Storage::assertExists('exports/'.$export->storage_key.'/package/listings.csv');
        Storage::assertExists('exports/'.$export->storage_key.'/package/listings.xlsx');

        $this->get('/d/'.$token)
            ->assertOk()
            ->assertSee('Download zip', false);

        $this->get('/d/'.$token.'/download')
            ->assertOk();
    }

    public function test_download_returns_410_after_expiry(): void
    {
        Storage::fake();

        $token = bin2hex(random_bytes(32));

        $storageKey = '00000000-0000-4000-8000-000000000002';

        $export = ListingExport::query()->create([
            'storage_key' => $storageKey,
            'delivery_token_hash' => hash('sha256', $token),
            'listing_page_url' => 'https://example.com/inventory',
            'status' => ListingExport::STATUS_READY,
            'expires_at' => now()->subMinute(),
            'zip_relative_path' => 'exports/'.$storageKey.'/export.zip',
        ]);

        Storage::put($export->zip_relative_path, 'zip-bytes');

        $this->get('/d/'.$token)->assertStatus(410);
        $this->get('/d/'.$token.'/download')->assertStatus(410);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['facebook_marketplace.max_listings_per_job' => 25]);
        config(['facebook_marketplace.max_total_image_bytes' => 1024 * 1024]);
        config(['openai.listing_index_openai_enabled' => false]);
        config(['openai.listing_inventory_catalog_enabled' => false]);
        config([
            'openai.listing_detail_images_openai_enabled' => false,
            'openai.listing_detail_images_refine_when_present' => false,
            'openai.listing_image_vision_enabled' => false,
        ]);
    }

    public function test_fetch_index_uses_openai_when_http_is_sparse_and_fallback_enabled(): void
    {
        Storage::fake();

        config(['openai.listing_index_openai_enabled' => true]);
        config(['openai.api_key' => 'sk-test']);
        config(['openai.base_url' => 'https://api.openai.com/v1']);

        $indexHtml = '<html><body><a href="/about">About</a>'
            .'<a href="/listings/2019-honda-civic">Vehicle</a></body></html>';
        $listingPageUrl = 'https://example.com/search';

        $listingHtml = <<<'HTML'
            <html><head>
                <script type="application/ld+json">
                {
                    "@type": "Product",
                    "name": "2019 Honda Civic LX",
                    "description": "Low miles",
                    "image": "https://example.com/photo.jpg",
                    "offers": { "@type": "Offer", "price": "12995", "priceCurrency": "USD" }
                }
                </script>
            </head><body></body></html>
        HTML;

        Http::fake(function (Request $request) use ($indexHtml, $listingHtml, $listingPageUrl) {
            $url = $request->url();

            if (str_contains($url, 'api.openai.com/v1/responses')) {
                return Http::response([
                    'output' => [[
                        'type' => 'message',
                        'role' => 'assistant',
                        'content' => [[
                            'type' => 'output_text',
                            'text' => 'Found inventory link https://example.com/listings/2019-honda-civic',
                        ]],
                    ]],
                ], 200);
            }

            if (str_contains($url, parse_url($listingPageUrl, PHP_URL_HOST).'/search')) {
                return Http::response($indexHtml, 200);
            }

            if (str_contains($url, 'example.com/listings/')) {
                return Http::response($listingHtml, 200);
            }

            if (str_contains($url, 'example.com/photo.jpg')) {
                return Http::response(str_repeat('x', 32), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('not found', 404);
        });

        $token = bin2hex(random_bytes(32));

        $export = ListingExport::query()->create([
            'storage_key' => '00000000-0000-4000-8000-000000000004',
            'delivery_token_hash' => hash('sha256', $token),
            'listing_page_url' => $listingPageUrl,
            'status' => ListingExport::STATUS_QUEUED,
        ]);

        (new FetchListingIndexJob($export->id))->handle(
            app(UrlSafetyValidator::class),
            app(ListingIndexDiscoveryService::class),
        );

        $export->refresh();

        $urls = $export->discovered_urls ?? [];
        $this->assertIsArray($urls);
        $this->assertContains('https://example.com/listings/2019-honda-civic', $urls);
    }
}
