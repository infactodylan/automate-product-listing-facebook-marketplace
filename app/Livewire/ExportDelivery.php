<?php

namespace App\Livewire;

use App\Models\ListingExport;
use Livewire\Component;
use Livewire\Features\SupportPageComponents\BaseTitle;

#[BaseTitle('Download export')]
class ExportDelivery extends Component
{
    public string $token;

    public function mount(string $token): void
    {
        $this->token = $token;

        $export = ListingExport::query()
            ->where('delivery_token_hash', hash('sha256', $token))
            ->first();

        if (! $export) {
            abort(404);
        }

        if ($export->expires_at !== null && now()->greaterThan($export->expires_at)) {
            abort(410);
        }
    }

    public function getExportProperty(): ?ListingExport
    {
        return ListingExport::query()
            ->where('delivery_token_hash', hash('sha256', $this->token))
            ->first();
    }

    public function render()
    {
        return view('livewire.export-delivery');
    }
}
