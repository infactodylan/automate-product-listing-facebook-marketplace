<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Services\FacebookMarketplace\MarketplaceExportPackageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class BuildExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public int $listingExportId) {}

    public function handle(MarketplaceExportPackageBuilder $builder): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        $export->update([
            'status' => ListingExport::STATUS_DOWNLOADING_IMAGES,
            'error_message' => null,
        ]);

        /** @var array<int, array<string, mixed>>|null $products */
        $products = $export->scraped_products;
        if (! is_array($products) || $products === []) {
            throw new \RuntimeException('Missing scraped listings payload.');
        }

        $export->update([
            'status' => ListingExport::STATUS_BUILDING_PACKAGE,
        ]);

        $zipRelative = $builder->build($export, $products);

        $ttlDays = (int) config('facebook_marketplace.export_link_ttl_days');

        // Delivery links remain valid for N days starting when the export finishes successfully.
        $export->update([
            'zip_relative_path' => $zipRelative,
            'status' => ListingExport::STATUS_READY,
            'expires_at' => now()->addDays($ttlDays),
            'completed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $export = ListingExport::query()->find($this->listingExportId);
        if (! $export) {
            return;
        }

        if ($export->zip_relative_path) {
            Storage::delete($export->zip_relative_path);
        }

        Storage::deleteDirectory('exports/'.$export->storage_key);

        $export->update([
            'zip_relative_path' => null,
            'status' => ListingExport::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Failed while building the export package.',
        ]);
    }
}
