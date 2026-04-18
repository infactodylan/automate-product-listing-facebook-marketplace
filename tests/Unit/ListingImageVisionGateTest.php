<?php

namespace Tests\Unit;

use App\Services\OpenAi\ListingImageVisionGate;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingImageVisionGateTest extends TestCase
{
    public function test_it_filters_urls_using_chat_completions_json(): void
    {
        config([
            'openai.api_key' => 'sk-test',
            'openai.base_url' => 'https://api.openai.com/v1',
            'openai.listing_image_vision_enabled' => true,
            'openai.listing_image_vision_model' => 'gpt-5.4',
            'openai.listing_image_vision_max_candidates' => 12,
            'facebook_marketplace.max_images_per_listing' => 5,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'results' => [
                                ['url' => 'https://cdn.example.com/a.jpg', 'keep' => true, 'reason' => 'Listing photo.'],
                                ['url' => 'https://cdn.example.com/b.jpg', 'keep' => false, 'reason' => 'Logo.'],
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $products = [[
            'title' => 'Widget',
            'description' => 'Good',
            'image_urls' => [
                'https://cdn.example.com/a.jpg',
                'https://cdn.example.com/b.jpg',
            ],
            'source_url' => 'https://example.com/listing',
        ]];

        $out = app(ListingImageVisionGate::class)->filterScrapedProducts($products);

        $this->assertSame(['https://cdn.example.com/a.jpg'], $out[0]['image_urls']);
        $this->assertArrayHasKey('image_vision_manifest', $out[0]);
    }

    public function test_it_is_noop_when_disabled(): void
    {
        config([
            'openai.api_key' => 'sk-test',
            'openai.listing_image_vision_enabled' => false,
        ]);

        Http::fake();

        $products = [[
            'title' => 'Widget',
            'image_urls' => ['https://cdn.example.com/a.jpg'],
            'source_url' => 'https://example.com/listing',
        ]];

        $out = app(ListingImageVisionGate::class)->filterScrapedProducts($products);

        $this->assertSame($products[0]['image_urls'], $out[0]['image_urls']);
        Http::assertNothingSent();
    }
}
