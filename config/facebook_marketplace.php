<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Facebook Marketplace bulk upload template (canonical)
    |--------------------------------------------------------------------------
    |
    | Exports MUST be produced by filling this workbook — not by inventing a new
    | sheet layout — so dropdowns, the hidden VALIDATION sheet, and row layout
    | stay identical to Meta's template. Path may be overridden via .env when
    | Meta publishes an updated file.
    |
    */

    'bulk_upload_template_path' => env(
        'FACEBOOK_BULK_UPLOAD_TEMPLATE_PATH',
        storage_path('Facebook Bulk Upload Template.xlsx'),
    ),

];
