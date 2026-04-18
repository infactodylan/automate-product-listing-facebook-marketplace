<div>
    <header class="navbar border-b border-base-300 bg-base-100 px-4">
        <div class="flex flex-1 items-center justify-between gap-4">
            <a href="{{ route('home') }}" wire:navigate class="text-lg font-semibold">{{ config('app.name') }}</a>
            <a href="{{ route('exports.index') }}" wire:navigate class="btn btn-ghost btn-sm">All exports</a>
        </div>
    </header>

    <main
        class="container mx-auto max-w-2xl px-4 py-10"
        @if ($activeExportId && ! $deliveryUrl && ! $errorMessage)
            wire:poll.2s="pollExport"
        @endif
    >
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight">Export listings for Facebook Marketplace</h1>
            <p class="mt-2 text-base-content/70">
                Paste your public listings, shop, or catalog page URL. We will build Meta’s bulk upload workbook and zip your images grouped by listing title.
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
                        class="input input-bordered w-full @error('listingPageUrl') input-error @enderror"
                        placeholder="https://your-site.com/listings"
                        autocomplete="off"
                    />
                    @error('listingPageUrl')
                        <div class="label">
                            <span class="label-text-alt text-error">{{ $message }}</span>
                        </div>
                    @enderror
                </label>

                @php($export = $this->activeExport)

                @if ($activeExportId)
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm font-medium">Progress</span>
                            <span class="text-sm text-base-content/70">{{ $this->progressLabel($export) }}</span>
                        </div>
                        <progress class="progress progress-primary w-full" value="{{ $this->progressValue($export) }}" max="100"></progress>
                    </div>
                @endif

                @if ($errorMessage)
                    <div class="alert alert-error text-sm">
                        <span>{{ $errorMessage }}</span>
                    </div>
                @endif

                @if ($deliveryUrl)
                    <div class="alert alert-success">
                        <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <div class="font-medium">Your download link</div>
                                <div class="truncate text-sm opacity-80" title="{{ $deliveryUrl }}">{{ $deliveryUrl }}</div>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    x-data="{ url: @js($deliveryUrl) }"
                                    x-on:click="navigator.clipboard.writeText(url)"
                                >
                                    Copy
                                </button>
                                <a class="btn btn-sm btn-primary" href="{{ $deliveryUrl }}" wire:navigate>Open</a>
                            </div>
                        </div>
                    </div>

                    @if ($export?->expires_at)
                        <div class="text-sm text-base-content/70">
                            Link expires {{ $export->expires_at->timezone(config('app.timezone'))->toDayDateTimeString() }}.
                        </div>
                    @endif
                @endif

                <div class="card-actions justify-end">
                    <button
                        type="button"
                        class="btn btn-primary"
                        wire:click="generateExport"
                        wire:loading.attr="disabled"
                        @if ($activeExportId && ! $deliveryUrl && ! $errorMessage)
                            disabled
                        @endif
                    >
                        <span wire:loading.remove wire:target="generateExport">Generate export</span>
                        <span wire:loading wire:target="generateExport" class="loading loading-spinner loading-sm"></span>
                    </button>
                </div>
            </div>
        </div>
    </main>
</div>
