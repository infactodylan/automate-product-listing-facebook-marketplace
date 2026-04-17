<div>
    <header class="navbar border-b border-base-300 bg-base-100 px-4">
        <a href="/" wire:navigate class="btn btn-ghost btn-sm">Home</a>
        <span class="text-lg font-semibold">{{ config('app.name') }}</span>
    </header>

    <main class="container mx-auto max-w-2xl px-4 py-10">
        @php($export = $this->export)

        @if ($export->status !== \App\Models\ListingExport::STATUS_READY)
            <div class="alert alert-info">
                <span>This export is not ready yet. Status: {{ $export->status }}</span>
            </div>
        @else
            <div class="mb-8">
                <h1 class="text-3xl font-bold tracking-tight">Your Facebook Marketplace export</h1>
                <p class="mt-2 text-base-content/70">
                    The zip contains <code class="font-mono text-xs">listings*.xlsx</code> plus one folder per listing title with numbered images.
                </p>
            </div>

            <div class="card bg-base-100 shadow-xl">
                <div class="card-body gap-4">
                    <div class="flex flex-col gap-2">
                        <span class="text-sm text-base-content/70">Expires</span>
                        <span class="font-medium">{{ $export->expires_at?->timezone(config('app.timezone'))->toDayDateTimeString() }}</span>
                    </div>

                    <div class="card-actions justify-end">
                        <a class="btn btn-primary" href="{{ route('exports.download', ['token' => $token]) }}">
                            Download zip
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </main>
</div>
