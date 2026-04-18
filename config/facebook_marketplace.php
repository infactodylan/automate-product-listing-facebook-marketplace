<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Facebook Marketplace bulk upload template (canonical)
    |--------------------------------------------------------------------------
    */

    'bulk_upload_template_path' => env(
        'FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH',
        storage_path('Facebook Bulk Upload Template.csv'),
    ),

    /*
    |--------------------------------------------------------------------------
    | Export delivery link TTL
    |--------------------------------------------------------------------------
    |
    | Links are valid for N days starting when the export reaches "ready"
    | (successful job completion). See ListingExport::$expires_at.
    |
    */

    'export_link_ttl_days' => (int) env('EXPORT_LINK_TTL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Scraping limits
    |--------------------------------------------------------------------------
    */

    'http_user_agent' => env('HTTP_USER_AGENT', 'AutomateFacebookMarketplaceExport/1.0 (+https://example.invalid)'),

    'scraper_timeout_seconds' => (int) env('SCRAPER_TIMEOUT_SECONDS', 45),

    'max_listings_per_job' => (int) env('MAX_LISTINGS_PER_JOB', 100),

    /** Total bytes for all downloaded listing images per export job (approximate cap). */
    'max_total_image_bytes' => (int) env('MAX_TOTAL_IMAGE_BYTES', 120 * 1024 * 1024),

    /** Max images downloaded per listing (0 = no per-listing cap). */
    'max_images_per_listing' => (int) env('MAX_IMAGES_PER_LISTING', 5),

    /**
     * If set (>0) and PHP GD is available, downscale saved listing images so neither edge exceeds these bounds.
     * Uses proportional scaling (does not crop). 0 = no resizing.
     */
    'image_output_max_width' => (int) env('IMAGE_OUTPUT_MAX_WIDTH', 0),

    'image_output_max_height' => (int) env('IMAGE_OUTPUT_MAX_HEIGHT', 0),

    /**
     * Concurrent image HTTP requests per listing (chunk size for Http::pool).
     * 1 = sequential downloads. Higher values speed up exports when each listing has multiple photos.
     */
    'image_download_concurrency' => (int) env('IMAGE_DOWNLOAD_CONCURRENCY', 8),

    /** Meta bulk upload guidance: batch listings (CSV rows after header); cap each file for parity with legacy XLSX limits. */
    'rows_per_workbook' => 50,

];
