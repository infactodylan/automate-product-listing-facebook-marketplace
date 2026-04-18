<?php

namespace Tests\Unit;

use App\Models\ListingExport;
use App\Services\FacebookMarketplace\MarketplaceExportPackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceExportPackageBuilderMaxImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_downloads_at_most_configured_images_per_listing(): void
    {
        Storage::fake();

        config(['facebook_marketplace.max_images_per_listing' => 2]);
        config(['facebook_marketplace.max_total_image_bytes' => 1024 * 1024]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'cdn.example.com')) {
                return Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('unexpected', 404);
        });

        $export = ListingExport::query()->create([
            'storage_key' => '00000000-0000-4000-8000-000000000099',
            'delivery_token_hash' => hash('sha256', 'token'),
            'listing_page_url' => 'https://example.com/inventory',
            'status' => ListingExport::STATUS_READY,
        ]);

        $products = [[
            'title' => '2019 Honda Civic',
            'price_usd' => 1000,
            'description' => 'Nice',
            'condition_raw' => null,
            'category' => null,
            'image_urls' => [
                'https://cdn.example.com/a.jpg',
                'https://cdn.example.com/b.jpg',
                'https://cdn.example.com/c.jpg',
                'https://cdn.example.com/d.jpg',
            ],
            'source_url' => 'https://example.com/vdp/1',
        ]];

        app(MarketplaceExportPackageBuilder::class)->build($export, $products);

        $folder = 'exports/'.$export->storage_key.'/package/2019 Honda Civic';

        Storage::assertExists($folder.'/01.jpg');
        Storage::assertExists($folder.'/02.jpg');
        Storage::assertMissing($folder.'/03.jpg');

        $cdnRequests = Http::recorded()->filter(function ($pair): bool {
            /** @var Request $request */
            $request = $pair[0];

            return str_contains($request->url(), 'cdn.example.com');
        });

        $this->assertCount(2, $cdnRequests);
    }
}
