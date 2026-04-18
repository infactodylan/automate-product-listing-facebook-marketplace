<?php

namespace Tests\Unit;

use App\Services\OpenAi\ListingInventoryCatalogOpenAi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingInventoryCatalogOpenAiTest extends TestCase
{
    public function test_it_returns_listing_urls_from_chat_completions_json(): void
    {
        config([
            'openai.api_key' => 'sk-test',
            'openai.base_url' => 'https://api.openai.com/v1',
            'openai.listing_inventory_catalog_model' => 'gpt-5.4',
            'facebook_marketplace.scraper_timeout_seconds' => 30,
        ]);

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'listing_urls' => [
                                'https://example.com/listings/a',
                                'https://example.com/listings/b',
                            ],
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $html = '<html><script type="application/ld+json">{"@type":"ItemList"}</script></html>';

        $urls = app(ListingInventoryCatalogOpenAi::class)->extractListingUrlsFromInventoryHtml(
            'https://example.com/inventory',
            $html,
            25,
        );

        $this->assertSame([
            'https://example.com/listings/a',
            'https://example.com/listings/b',
        ], $urls);
    }
}
