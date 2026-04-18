<?php

namespace Tests\Unit;

use App\Services\Scraping\JsonLdScriptExtractor;
use Tests\TestCase;

class JsonLdScriptExtractorTest extends TestCase
{
    public function test_it_extracts_application_ld_json_script_bodies(): void
    {
        $html = <<<'HTML'
            <html><head>
            <script type="application/ld+json">
            { "@type": "ItemList", "itemListElement": [] }
            </script>
            </head><body>
            <script type="application/ld+json">{"@type":"WebSite"}</script>
            </body></html>
        HTML;

        $blocks = JsonLdScriptExtractor::extractRawBlocks($html);

        $this->assertCount(2, $blocks);
        $this->assertStringContainsString('ItemList', $blocks[0]);
        $this->assertStringContainsString('WebSite', $blocks[1]);
    }
}
