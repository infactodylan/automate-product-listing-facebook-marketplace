<?php

namespace Tests\Unit;

use App\Services\Scraping\ListingIndexExtractor;
use Tests\TestCase;

class ListingIndexExtractorFilterListingUrlsTest extends TestCase
{
    public function test_it_filters_same_host_listing_paths_and_caps_max(): void
    {
        $extractor = new ListingIndexExtractor;

        $indexUrl = 'https://example.com/inventory';

        $urls = $extractor->filterListingUrls($indexUrl, [
            'https://example.com/listings/123-car',
            'https://other.com/listings/x',
            'https://example.com/about',
            'not-a-url',
            'https://example.com/listings/456-truck',
        ], 2);

        $this->assertSame([
            'https://example.com/listings/123-car',
            'https://example.com/listings/456-truck',
        ], $urls);
    }
}
