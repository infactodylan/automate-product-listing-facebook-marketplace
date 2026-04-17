<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Facebook Marketplace bulk upload template (canonical)
    |--------------------------------------------------------------------------
    */

    'bulk_upload_template_path' => env(
        'FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH',
        storage_path('Facebook Bulk Upload Template.xlsx'),
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

    /** Meta template supports 50 listings per workbook (rows 5–54). */
    'rows_per_workbook' => 50,

];
