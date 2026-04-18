<?php

use App\Http\Controllers\ExportDownloadController;
use App\Livewire\ExportDelivery;
use App\Livewire\ExportsList;
use App\Livewire\HomePage;
use Illuminate\Support\Facades\Route;

Route::get('/', HomePage::class)->name('home');

Route::get('/exports', ExportsList::class)->name('exports.index');

Route::get('/d/{token}', ExportDelivery::class)->name('exports.show');

Route::middleware(['throttle:download-export'])->group(function () {
    Route::get('/d/{token}/download', ExportDownloadController::class)->name('exports.download');
});
