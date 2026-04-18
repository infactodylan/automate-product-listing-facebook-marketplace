<?php

namespace App\Livewire;

use App\Models\ListingExport;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\Features\SupportPageComponents\BaseTitle;
use Livewire\WithPagination;

#[BaseTitle('All exports')]
class ExportsList extends Component
{
    use WithPagination;

    public function statusLabel(string $status): string
    {
        return match ($status) {
            ListingExport::STATUS_QUEUED => 'Queued',
            ListingExport::STATUS_FETCHING_INDEX => 'Fetching index',
            ListingExport::STATUS_SCRAPING_LISTINGS => 'Scraping listings',
            ListingExport::STATUS_DOWNLOADING_IMAGES => 'Downloading images',
            ListingExport::STATUS_BUILDING_PACKAGE => 'Building package',
            ListingExport::STATUS_READY => 'Ready',
            ListingExport::STATUS_FAILED => 'Failed',
            default => $status,
        };
    }

    /**
     * Approximate number of listings for display (discovered or scraped count).
     */
    public function listingCount(?ListingExport $export): ?int
    {
        if ($export === null) {
            return null;
        }

        if (is_array($export->scraped_products) && $export->scraped_products !== []) {
            return count($export->scraped_products);
        }

        if (is_array($export->discovered_urls) && $export->discovered_urls !== []) {
            return count($export->discovered_urls);
        }

        return null;
    }

    public function render(): View
    {
        $exports = ListingExport::query()
            ->latest()
            ->paginate(20);

        return view('livewire.exports-list', [
            'exports' => $exports,
        ]);
    }
}
