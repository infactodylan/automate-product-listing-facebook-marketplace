<?php

use Livewire\Component;
use Livewire\Features\SupportPageComponents\BaseTitle;

new
#[BaseTitle('Listing export')]
class extends Component
{
    public string $listingPageUrl = '';
};
?>

<div>
    <header class="navbar border-b border-base-300 bg-base-100 px-4">
        <span class="text-lg font-semibold">{{ config('app.name') }}</span>
    </header>

    <main class="container mx-auto max-w-2xl px-4 py-10">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight">Export listings for Facebook Marketplace</h1>
            <p class="mt-2 text-base-content/70">
                Add your product listings page URL. The app will build a spreadsheet and download a zip of images grouped by listing.
            </p>
        </div>

        <div class="card bg-base-100 shadow-xl">
            <div class="card-body gap-6">
                <label class="form-control w-full">
                    <div class="label">
                        <span class="label-text font-medium">Listings page URL</span>
                    </div>
                    <input
                        type="url"
                        wire:model.live="listingPageUrl"
                        class="input input-bordered w-full"
                        placeholder="https://your-dealer-site.com/inventory"
                        autocomplete="off"
                    />
                </label>

                <div class="alert alert-info text-sm">
                    <span>
                        Processing, Excel layout, and delivery links are specified in <code class="font-mono text-xs">spec.md</code>.
                    </span>
                </div>

                <div class="card-actions justify-end">
                    <button type="button" class="btn btn-primary" disabled>
                        Generate export (next)
                    </button>
                </div>
            </div>
        </div>
    </main>
</div>
