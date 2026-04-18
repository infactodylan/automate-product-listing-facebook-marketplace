<?php

namespace App\Livewire;

use App\Jobs\BuildExportJob;
use App\Jobs\FetchListingIndexJob;
use App\Jobs\ScrapeListingJob;
use App\Models\ListingExport;
use App\Services\UrlSafetyValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportPageComponents\BaseTitle;

#[BaseTitle('Listing export')]
class HomePage extends Component
{
    public string $listingPageUrl = '';

    public ?int $activeExportId = null;

    public ?string $deliveryUrl = null;

    public ?string $errorMessage = null;

    public function mount(Request $request): void
    {
        $candidate = $request->query('url')
            ?? $request->query('listing_page_url')
            ?? $request->query('listing_url');

        if (! is_string($candidate)) {
            return;
        }

        $candidate = trim($candidate);

        if ($candidate === '') {
            return;
        }

        if (Validator::make(
            ['listingPageUrl' => $candidate],
            ['listingPageUrl' => ['required', 'url']],
        )->passes()) {
            $this->listingPageUrl = $candidate;
        }
    }

    public function generateExport(UrlSafetyValidator $urlSafety): void
    {
        $this->errorMessage = null;
        $this->deliveryUrl = null;

        $this->validate([
            'listingPageUrl' => ['required', 'url'],
        ]);

        try {
            $urlSafety->assertPublicHttpUrl($this->listingPageUrl);
        } catch (\Throwable $e) {
            $this->addError('listingPageUrl', $e->getMessage());

            return;
        }

        $token = bin2hex(random_bytes(32));

        $export = ListingExport::query()->create([
            'storage_key' => (string) Str::uuid(),
            'delivery_token_hash' => hash('sha256', $token),
            'listing_page_url' => $this->listingPageUrl,
            'status' => ListingExport::STATUS_QUEUED,
        ]);

        Session::put('pending_export', [
            'id' => $export->id,
            'token' => $token,
        ]);

        $this->activeExportId = $export->id;

        Bus::chain([
            new FetchListingIndexJob($export->id),
            new ScrapeListingJob($export->id),
            new BuildExportJob($export->id),
        ])->dispatch();

        $this->pollExport();
    }

    public function pollExport(): void
    {
        $pending = Session::get('pending_export');
        if (! is_array($pending) || ! isset($pending['id'], $pending['token'])) {
            return;
        }

        if ((int) $pending['id'] !== (int) $this->activeExportId) {
            return;
        }

        $export = ListingExport::query()->find($this->activeExportId);
        if (! $export) {
            return;
        }

        if ($export->status === ListingExport::STATUS_READY) {
            $this->deliveryUrl = url('/d/'.$pending['token']);
            $this->errorMessage = null;
        }

        if ($export->status === ListingExport::STATUS_FAILED) {
            $this->errorMessage = $export->error_message ?? 'Export failed.';
            $this->deliveryUrl = null;
        }
    }

    public function progressLabel(?ListingExport $export): string
    {
        if (! $export) {
            return 'Queued';
        }

        return match ($export->status) {
            ListingExport::STATUS_QUEUED => 'Queued',
            ListingExport::STATUS_FETCHING_INDEX => 'Fetching listings index',
            ListingExport::STATUS_SCRAPING_LISTINGS => 'Parsing listings',
            ListingExport::STATUS_DOWNLOADING_IMAGES => 'Building spreadsheet and package',
            ListingExport::STATUS_BUILDING_PACKAGE => 'Building spreadsheet, downloading images, creating zip',
            ListingExport::STATUS_READY => 'Ready',
            ListingExport::STATUS_FAILED => 'Failed',
            default => $export->status,
        };
    }

    public function progressValue(?ListingExport $export): int
    {
        if (! $export) {
            return 5;
        }

        return match ($export->status) {
            ListingExport::STATUS_QUEUED => 5,
            ListingExport::STATUS_FETCHING_INDEX => 20,
            ListingExport::STATUS_SCRAPING_LISTINGS => 45,
            ListingExport::STATUS_DOWNLOADING_IMAGES => 75,
            ListingExport::STATUS_BUILDING_PACKAGE => 75,
            ListingExport::STATUS_READY => 100,
            ListingExport::STATUS_FAILED => 100,
            default => 10,
        };
    }

    public function getActiveExportProperty(): ?ListingExport
    {
        if (! $this->activeExportId) {
            return null;
        }

        return ListingExport::query()->find($this->activeExportId);
    }

    public function render()
    {
        return view('livewire.home-page');
    }
}
