<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Models\ListingExportScrapeResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Merges parallel {@see ScrapeSingleListingPageJob} rows into {@see ListingExport::$scraped_products}
 * and continues the export pipeline.
 */
class FinalizeListingScrapeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $listingExportId) {}

    public function handle(): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        /** @var list<array<string, mixed>> $products */
        $products = [];

        $rows = ListingExportScrapeResult::query()
            ->where('listing_export_id', $export->id)
            ->orderBy('position')
            ->get();

        foreach ($rows as $row) {
            if (is_array($row->product) && $row->product !== []) {
                $products[] = $row->product;
            }
        }

        if ($products === []) {
            $export->update([
                'status' => ListingExport::STATUS_FAILED,
                'error_message' => 'No listings could be parsed. The site may block automated access, require JavaScript rendering, or use an unsupported layout.',
            ]);

            return;
        }

        $export->update([
            'scraped_products' => $products,
            'error_message' => null,
        ]);

        BuildExportJob::dispatch($this->listingExportId);
    }

    public function failed(?\Throwable $exception): void
    {
        $export = ListingExport::query()->find($this->listingExportId);
        if (! $export) {
            return;
        }

        if ($export->status === ListingExport::STATUS_READY) {
            return;
        }

        $export->update([
            'status' => ListingExport::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Failed while merging scraped listings.',
        ]);
    }
}
