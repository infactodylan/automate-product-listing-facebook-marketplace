<?php

namespace App\Console\Commands;

use App\Models\ListingExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeExpiredExportsCommand extends Command
{
    protected $signature = 'exports:purge-expired';

    protected $description = 'Delete expired export artifacts after a safety buffer beyond the advertised expiry.';

    public function handle(): int
    {
        $bufferHours = max(24, (int) env('EXPORT_PURGE_BUFFER_HOURS', 24));

        $cutoff = now()->subHours($bufferHours);

        ListingExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($exports): void {
                foreach ($exports as $export) {
                    Storage::deleteDirectory('exports/'.$export->storage_key);
                    $export->delete();
                }
            });

        return self::SUCCESS;
    }
}
