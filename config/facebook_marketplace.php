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

    /*
    |--------------------------------------------------------------------------
    | Browserless (JS-rendered HTML)
    |--------------------------------------------------------------------------
    |
    | POST /content returns fully rendered HTML. Requires a Browserless token.
    | Docs: https://docs.browserless.io/rest-apis/content
    |
    */

    'browserless' => [
        'enabled' => filter_var(env('BROWSERLESS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'token' => env('BROWSERLESS_TOKEN'),
        /** e.g. https://production-sfo.browserless.io (region-specific) */
        'base_url' => rtrim((string) env(
            'BROWSERLESS_BASE_URL',
            'https://production-sfo.browserless.io',
        ), '/'),
        /** Seconds for the Browserless request (navigation + render can exceed plain HTTP). */
        'timeout_seconds' => max(15, (int) env('BROWSERLESS_TIMEOUT_SECONDS', 90)),
        /** When true (and token set), listing/index HTML is fetched via Browserless first. */
        'use_for_scraping' => filter_var(env('BROWSERLESS_USE_FOR_SCRAPING', false), FILTER_VALIDATE_BOOLEAN),
        /** If Browserless fails or returns empty HTML, fall back to Laravel HTTP GET. */
        'fallback_to_http' => filter_var(env('BROWSERLESS_FALLBACK_TO_HTTP', true), FILTER_VALIDATE_BOOLEAN),
        /** Optional extra wait in ms before capturing HTML (SPA hydration). 0 = omit. */
        'wait_for_timeout_ms' => max(0, (int) env('BROWSERLESS_WAIT_FOR_TIMEOUT_MS', 0)),
    ],

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
