<?php

use App\Http\Controllers\ExportDownloadController;
use App\Livewire\ExportDelivery;
use App\Livewire\HomePage;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:create-export'])->group(function () {
    Route::get('/', HomePage::class);
});

Route::get('/d/{token}', ExportDelivery::class)->name('exports.show');

Route::middleware(['throttle:download-export'])->group(function () {
    Route::get('/d/{token}/download', ExportDownloadController::class)->name('exports.download');
});
