<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API (Responses API)
    |--------------------------------------------------------------------------
    |
    | listing index + optional per-listing image URL discovery use web_search.
    | Set OPENAI_API_KEY in .env.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),

    'max_tool_rounds' => max(1, (int) env('OPENAI_MAX_TOOL_ROUNDS', 75)),

    /*
    |--------------------------------------------------------------------------
    | Listing index (discover VDP / listing URLs)
    |--------------------------------------------------------------------------
    */

    'listing_index_model' => env('OPENAI_LISTING_INDEX_MODEL', 'gpt-5'),

    'listing_index_reasoning_effort' => env('OPENAI_LISTING_INDEX_REASONING_EFFORT', 'medium'),

    /**
     * When true and OPENAI_API_KEY is set, index URL discovery uses OpenAI web_search
     * first. HTTP <a> parsing is only used if OpenAI returns no URLs or errors.
     * If unset, falls back to legacy OPENAI_LISTING_INDEX_FALLBACK_ENABLED, else true.
     */
    'listing_index_openai_enabled' => (static function (): bool {
        if (env('OPENAI_LISTING_INDEX_ENABLED') !== null) {
            return filter_var(env('OPENAI_LISTING_INDEX_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        }
        if (env('OPENAI_LISTING_INDEX_FALLBACK_ENABLED') !== null) {
            return filter_var(env('OPENAI_LISTING_INDEX_FALLBACK_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Listing detail images (when static HTML has no photos)
    |--------------------------------------------------------------------------
    */

    'listing_detail_images_model' => env('OPENAI_LISTING_DETAIL_IMAGES_MODEL', 'gpt-5'),

    'listing_detail_images_reasoning_effort' => env('OPENAI_LISTING_DETAIL_IMAGES_REASONING_EFFORT', 'medium'),

    /**
     * When true and OPENAI_API_KEY is set, if a listing page yields no image URLs
     * from HTML/JSON-LD, a second pass uses web_search to find photo URLs.
     */
    'listing_detail_images_openai_enabled' => (static function (): bool {
        if (env('OPENAI_LISTING_DETAIL_IMAGES_ENABLED') !== null) {
            return filter_var(env('OPENAI_LISTING_DETAIL_IMAGES_ENABLED'), FILTER_VALIDATE_BOOLEAN);
        }

        return true;
    })(),

];
