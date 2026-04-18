<?php

namespace Tests\Feature;

use App\Livewire\HomePage;
use Livewire\Livewire;
use Tests\TestCase;

class HomePageUrlQueryParameterTest extends TestCase
{
    public function test_it_prefills_listing_url_from_url_query_parameter(): void
    {
        Livewire::withQueryParams(['url' => 'https://example.com/inventory'])
            ->test(HomePage::class)
            ->assertSet('listingPageUrl', 'https://example.com/inventory');
    }

    public function test_it_prefills_from_listing_page_url_alias(): void
    {
        Livewire::withQueryParams(['listing_page_url' => 'https://dealer.example.org/search'])
            ->test(HomePage::class)
            ->assertSet('listingPageUrl', 'https://dealer.example.org/search');
    }

    public function test_it_prefills_from_listing_url_alias(): void
    {
        Livewire::withQueryParams(['listing_url' => 'https://cars.example.net/stock'])
            ->test(HomePage::class)
            ->assertSet('listingPageUrl', 'https://cars.example.net/stock');
    }

    public function test_url_takes_precedence_over_other_aliases(): void
    {
        Livewire::withQueryParams([
            'url' => 'https://first.example/page',
            'listing_page_url' => 'https://second.example/page',
            'listing_url' => 'https://third.example/page',
        ])
            ->test(HomePage::class)
            ->assertSet('listingPageUrl', 'https://first.example/page');
    }

    public function test_it_ignores_invalid_query_url(): void
    {
        Livewire::withQueryParams(['url' => 'not-a-valid-url'])
            ->test(HomePage::class)
            ->assertSet('listingPageUrl', '');
    }
}
