<?php

namespace App\Jobs;

use App\Models\ListingExport;
use App\Models\ListingExportScrapeResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches one {@see ScrapeSingleListingPageJob} per discovered URL so workers can scrape in parallel.
 */
class StartListingScrapeBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $listingExportId) {}

    public function handle(): void
    {
        $export = ListingExport::query()->findOrFail($this->listingExportId);

        $export->update([
            'status' => ListingExport::STATUS_SCRAPING_LISTINGS,
            'error_message' => null,
        ]);

        /** @var list<string> $urls */
        $urls = $export->discovered_urls ?? [];
        if ($urls === []) {
            $urls = [$export->listing_page_url];
        }

        ListingExportScrapeResult::query()->where('listing_export_id', $export->id)->delete();

        $jobs = [];
        foreach ($urls as $i => $url) {
            $jobs[] = new ScrapeSingleListingPageJob($export->id, $url, $i);
        }

        if ($jobs === []) {
            throw new \RuntimeException('No listing URLs to scrape.');
        }

        $exportId = $export->id;

        Bus::batch($jobs)
            ->name('listing-scrape-'.$exportId)
            ->allowFailures()
            ->then(function () use ($exportId): void {
                FinalizeListingScrapeJob::dispatch($exportId);
            })
            ->catch(function (Throwable $e) use ($exportId): void {
                Log::error('Listing scrape batch failed fatally', [
                    'export_id' => $exportId,
                    'error' => $e->getMessage(),
                ]);

                ListingExport::query()->whereKey($exportId)->update([
                    'status' => ListingExport::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            })
            ->dispatch();

        Log::info('Listing scrape batch dispatched', [
            'export_id' => $exportId,
            'job_count' => count($jobs),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $export = ListingExport::query()->find($this->listingExportId);
        if (! $export) {
            return;
        }

        $export->update([
            'status' => ListingExport::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?? 'Failed to start listing scrape batch.',
        ]);
    }
}
