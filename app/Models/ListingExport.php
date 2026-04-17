<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ListingExport extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_FETCHING_INDEX = 'fetching_index';

    public const STATUS_SCRAPING_LISTINGS = 'scraping_listings';

    public const STATUS_DOWNLOADING_IMAGES = 'downloading_images';

    public const STATUS_BUILDING_PACKAGE = 'building_package';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'storage_key',
        'delivery_token_hash',
        'listing_page_url',
        'status',
        'error_message',
        'discovered_urls',
        'scraped_products',
        'zip_relative_path',
        'expires_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discovered_urls' => 'array',
            'scraped_products' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
