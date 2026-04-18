<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API (Responses API)
    |--------------------------------------------------------------------------
    |
    | Defaults enable every integration below when unset in .env; services still
    | no-op until OPENAI_API_KEY is set. Override individual *_ENABLED env vars to false
    | to reduce cost or skip a step.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),

    /**
     * When true, writes under storage/app/openai-debug/<date>/…: Responses API JSON per round,
     * vision gate image downloads, and optional HTML snapshots. Off by default (latency + disk).
     */
    'debug_artifacts_enabled' => filter_var(
        env('OPENAI_DEBUG_ARTIFACTS', false),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'max_tool_rounds' => max(1, (int) env('OPENAI_MAX_TOOL_ROUNDS', 75)),

    /*
    |--------------------------------------------------------------------------
    | Listing index (discover VDP / listing URLs)
    |--------------------------------------------------------------------------
    */

    'listing_index_model' => env('OPENAI_LISTING_INDEX_MODEL', 'gpt-5.4'),

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

    'listing_detail_images_model' => env('OPENAI_LISTING_DETAIL_IMAGES_MODEL', 'gpt-5.4'),

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

    /**
     * When true and OPENAI_API_KEY is set, run web_search photo discovery even when HTML already yielded URLs.
     * Results merge with HTML extraction (lower priority than JSON-LD / DOM / meta).
     */
    'listing_detail_images_refine_when_present' => filter_var(
        env('OPENAI_LISTING_DETAIL_IMAGES_REFINE_WHEN_PRESENT', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    |--------------------------------------------------------------------------
    | Vision gate (approve listing images before download)
    |--------------------------------------------------------------------------
    */

    'listing_image_vision_enabled' => filter_var(
        env('OPENAI_LISTING_IMAGE_VISION_ENABLED', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'listing_image_vision_model' => env('OPENAI_LISTING_IMAGE_VISION_MODEL', 'gpt-5.4'),

    /** Max candidate URLs sent to the vision model per listing (cost/latency cap). */
    'listing_image_vision_max_candidates' => max(1, (int) env('OPENAI_LISTING_IMAGE_VISION_MAX_CANDIDATES', 12)),

];
