<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use VehicleData\Core\Http\Controllers\HealthController;
use VehicleData\Core\Http\Controllers\MakeController;
use VehicleData\Core\Http\Controllers\ManufacturerController;
use VehicleData\Core\Http\Controllers\OpenApiController;
use VehicleData\Core\Http\Controllers\SnapshotController;
use VehicleData\Core\Http\Controllers\TaxonomyController;
use VehicleData\Core\Http\Controllers\VariantController;
use VehicleData\Core\Http\Controllers\VehicleModelController;
use VehicleData\Core\Http\Controllers\VinController;

// The OPTIONS preflight for `/v1/*` is answered by the global
// ClientCorsPreflight middleware (bootstrap/app.php), not by a route here —
// see that class's docblock for why a wildcard route breaks plain 404s.

Route::get('openapi.yaml', OpenApiController::class)->middleware(['core', 'etag'])->name('openapi');

Route::prefix('v1')->name('v1.')->middleware('core')->group(function (): void {
    Route::get('health', [HealthController::class, 'live'])->name('health');
});

Route::prefix('v1')->name('v1.')->middleware('data')->group(function (): void {
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');

    Route::middleware(['scope:catalogue:read', 'etag'])->group(function (): void {
        $key = '[0-9A-HJKMNP-TV-Z]{26}|[a-z0-9-]+';
        Route::get('taxonomies', [TaxonomyController::class, 'index'])->name('taxonomies.index');
        Route::get('taxonomies/{key}', [TaxonomyController::class, 'show'])->where('key', '[0-9A-HJKMNP-TV-Z]{26}|[a-z_]+')->name('taxonomies.show');
        Route::get('manufacturers', [ManufacturerController::class, 'index'])->name('manufacturers.index');
        Route::get('manufacturers/{key}', [ManufacturerController::class, 'show'])->where('key', $key)->name('manufacturers.show');
        Route::get('makes', [MakeController::class, 'index'])->name('makes.index');
        Route::get('makes/{key}', [MakeController::class, 'show'])->where('key', $key)->name('makes.show');
        Route::get('makes/{key}/models', [MakeController::class, 'models'])->where('key', $key)->name('makes.models');
        Route::get('models/{key}', [VehicleModelController::class, 'show'])->where('key', $key)->name('models.show');
        Route::get('models/{key}/variants', [VehicleModelController::class, 'variants'])->where('key', $key)->name('models.variants');
        Route::get('variants/{id}', [VariantController::class, 'show'])->where('id', '[0-9A-HJKMNP-TV-Z]{26}')->name('variants.show');
    });

    Route::middleware(['scope:vin:decode', 'etag'])->group(function (): void {
        Route::get('vin/{vin}', [VinController::class, 'show'])->where('vin', '[A-Za-z0-9]{1,32}')->name('vin.show');
    });

    // No `etag` middleware: the response is a streamed gzip body, not a buffered JSON
    // payload it could compute a strong ETag from.
    Route::middleware(['scope:snapshot:read'])->group(function (): void {
        Route::get('snapshots/{resource}', [SnapshotController::class, 'show'])->where('resource', '[a-z]+')->name('snapshots.show');
    });
});
