<?php

namespace Tests\Unit;

use App\Services\Scraping\ListingImageUrlNormalizer;
use App\Services\Scraping\SrcsetParser;
use Tests\TestCase;

class ListingImageUrlNormalizerTest extends TestCase
{
    public function test_merge_tier_prefers_json_ld_over_meta_for_same_canonical_asset(): void
    {
        $merged = ListingImageUrlNormalizer::mergeTieredUnique([
            ['url' => 'https://cdn.example.com/a.jpg?width=400', 'tier' => 2],
            ['url' => 'https://cdn.example.com/a.jpg?w=800', 'tier' => 0],
        ]);

        $this->assertSame(['https://cdn.example.com/a.jpg?w=800'], $merged);
    }

    public function test_srcset_parser_prefers_largest_width_descriptor(): void
    {
        $picked = SrcsetParser::pickLargestAbsoluteUrl(
            'https://cdn.example.com/small.jpg 320w, https://cdn.example.com/large.jpg 800w',
            'https://example.com/page',
        );

        $this->assertSame('https://cdn.example.com/large.jpg', $picked);
    }
}
