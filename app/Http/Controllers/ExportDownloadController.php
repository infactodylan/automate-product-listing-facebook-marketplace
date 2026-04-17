<?php

namespace App\Http\Controllers;

use App\Models\ListingExport;
use Illuminate\Support\Facades\Storage;

class ExportDownloadController extends Controller
{
    public function __invoke(string $token)
    {
        $export = ListingExport::query()
            ->where('delivery_token_hash', hash('sha256', $token))
            ->first();

        if (! $export || $export->status !== ListingExport::STATUS_READY) {
            abort(404);
        }

        if ($export->expires_at === null || now()->greaterThan($export->expires_at)) {
            abort(410);
        }

        $relative = $export->zip_relative_path;

        if (! $relative || ! Storage::exists($relative)) {
            abort(410);
        }

        return Storage::download($relative, 'facebook-marketplace-export.zip');
    }
}
