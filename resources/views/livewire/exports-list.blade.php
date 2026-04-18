<div>
    <header class="navbar border-b border-base-300 bg-base-100 px-4">
        <div class="flex flex-1 items-center gap-4">
            <a href="{{ route('home') }}" wire:navigate class="link link-hover text-lg font-semibold">{{ config('app.name') }}</a>
            <span class="text-base-content/40">/</span>
            <span class="text-lg font-medium">Exports</span>
        </div>
        <div class="flex-none">
            <a href="{{ route('home') }}" wire:navigate class="btn btn-ghost btn-sm">New export</a>
        </div>
    </header>

    <main class="container mx-auto max-w-5xl px-4 py-10">
        <div class="mb-8 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight">All exports</h1>
                <p class="mt-2 text-base-content/70">
                    Status and timing for every listing export job. Download links use a secret token and are only shown on the page right after you generate an export.
                </p>
            </div>
        </div>

        @php
            $hasInProgress = collect($exports->items())->contains(
                fn ($e) => ! in_array($e->status, [\App\Models\ListingExport::STATUS_READY, \App\Models\ListingExport::STATUS_FAILED], true),
            );
        @endphp

        <div @if ($hasInProgress) wire:poll.3s @endif class="overflow-x-auto rounded-lg border border-base-300 bg-base-100 shadow-sm">
            <table class="table table-sm md:table-md">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Source URL</th>
                        <th>Listings</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Completed</th>
                        <th>Expires</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($exports as $export)
                        <tr wire:key="export-row-{{ $export->id }}">
                            <td class="whitespace-nowrap font-mono text-xs">{{ $export->id }}</td>
                            <td class="max-w-[14rem] truncate sm:max-w-xs md:max-w-md" title="{{ $export->listing_page_url }}">
                                {{ $export->listing_page_url }}
                            </td>
                            <td>
                                @if (($n = $this->listingCount($export)) !== null)
                                    {{ $n }}
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($export->status === \App\Models\ListingExport::STATUS_READY)
                                    <span class="badge badge-success badge-sm">{{ $this->statusLabel($export->status) }}</span>
                                @elseif ($export->status === \App\Models\ListingExport::STATUS_FAILED)
                                    <span class="badge badge-error badge-sm">{{ $this->statusLabel($export->status) }}</span>
                                @else
                                    <span class="badge badge-info badge-sm">{{ $this->statusLabel($export->status) }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-base-content/80">
                                {{ $export->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                            </td>
                            <td class="whitespace-nowrap text-sm text-base-content/80">
                                @if ($export->completed_at)
                                    {{ $export->completed_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-base-content/80">
                                @if ($export->expires_at)
                                    {{ $export->expires_at->timezone(config('app.timezone'))->format('M j, Y g:i A') }}
                                @else
                                    <span class="text-base-content/50">—</span>
                                @endif
                            </td>
                        </tr>
                        @if ($export->status === \App\Models\ListingExport::STATUS_FAILED && $export->error_message)
                            <tr wire:key="export-err-{{ $export->id }}" class="bg-error/5">
                                <td colspan="7" class="text-sm text-error">
                                    {{ \Illuminate\Support\Str::limit($export->error_message, 240) }}
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-base-content/60">No exports yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $exports->links() }}
        </div>
    </main>
</div>
