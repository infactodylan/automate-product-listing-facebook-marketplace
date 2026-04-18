<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingExportScrapeResult extends Model
{
    protected $fillable = [
        'listing_export_id',
        'position',
        'source_url',
        'product',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product' => 'array',
        ];
    }

    public function listingExport(): BelongsTo
    {
        return $this->belongsTo(ListingExport::class);
    }
}
