<?php

namespace Tests\Unit;

use App\Services\Scraping\ListingPageScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingPageScraperImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openai.listing_detail_images_openai_enabled' => false,
            'openai.listing_detail_images_refine_when_present' => false,
        ]);
    }

    public function test_it_collects_vehicle_json_ld_lazy_img_sources_in_gallery_and_meta_images(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:image" content="https://cdn.example.com/og.jpg">
                <script type="application/ld+json">
                {
                    "@context": "https://schema.org",
                    "@type": "Vehicle",
                    "name": "2024 Toyota Camry",
                    "image": "https://cdn.example.com/hero.jpg"
                }
                </script>
            </head><body>
                <header><img src="https://cdn.example.com/header-promo.jpg" alt=""></header>
                <div class="swiper vehicle-gallery">
                    <img data-src="https://cdn.example.com/lazy.jpg" alt="">
                    <img srcset="https://cdn.example.com/small.jpg 320w, https://cdn.example.com/large.jpg 800w" src="https://cdn.example.com/small.jpg" alt="">
                </div>
            </body></html>
        HTML;

        Http::fake([
            'example.com/vdp' => Http::response($html, 200),
        ]);

        $row = app(ListingPageScraper::class)->scrape('https://example.com/vdp');

        foreach ([
            'https://cdn.example.com/hero.jpg',
            'https://cdn.example.com/og.jpg',
            'https://cdn.example.com/lazy.jpg',
            'https://cdn.example.com/large.jpg',
        ] as $u) {
            $this->assertContains($u, $row['image_urls']);
        }

        $this->assertNotContains('https://cdn.example.com/small.jpg', $row['image_urls']);

        $this->assertNotContains('https://cdn.example.com/header-promo.jpg', $row['image_urls']);
    }

    public function test_it_collects_inventory_like_paths_without_gallery_classes(): void
    {
        $html = <<<'HTML'
            <html><head></head><body>
                <main>
                    <img src="https://inventory.example.net/stock/abc/full/01.jpg" alt="">
                </main>
            </body></html>
        HTML;

        Http::fake([
            'example.com/vdp' => Http::response($html, 200),
        ]);

        $row = app(ListingPageScraper::class)->scrape('https://example.com/vdp');

        $this->assertContains('https://inventory.example.net/stock/abc/full/01.jpg', $row['image_urls']);
    }
}
