<?php

namespace Tests\Feature;

use App\Models\ListingExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportsListPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_index_renders_table(): void
    {
        ListingExport::query()->create([
            'storage_key' => '00000000-0000-4000-8000-000000000001',
            'delivery_token_hash' => hash('sha256', 'test-token'),
            'listing_page_url' => 'https://example.com/inventory',
            'status' => ListingExport::STATUS_READY,
        ]);

        $this->get(route('exports.index'))
            ->assertOk()
            ->assertSee('All exports', false)
            ->assertSee('https://example.com/inventory', false);
    }
}
