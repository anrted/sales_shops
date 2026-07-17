<?php

use App\Http\Controllers\Api\AdminParseRunController;
use App\Http\Controllers\Api\AdminLentaController;
use App\Http\Controllers\Api\AdminMagnitController;
use App\Http\Controllers\Api\AdminMetroController;
use App\Http\Controllers\Api\CatalogController;
use Illuminate\Support\Facades\Route;

Route::get('/cities', [CatalogController::class, 'cities']);
Route::get('/chains', [CatalogController::class, 'chains']);
Route::get('/categories', [CatalogController::class, 'categories']);
Route::get('/stores', [CatalogController::class, 'stores']);
Route::get('/discounts', [CatalogController::class, 'discounts']);
Route::get('/products/{product}', [CatalogController::class, 'product']);

Route::prefix('admin')->group(function (): void {
    Route::post('/magnit/stores/sync', [AdminMagnitController::class, 'syncStores']);
    Route::post('/magnit/categories/sync', [AdminMagnitController::class, 'syncCategories']);
    Route::post('/magnit/stores/import', [AdminMagnitController::class, 'importStores']);
    Route::post('/magnit/stores/import-yandex', [AdminMagnitController::class, 'importYandexStores']);
    Route::get('/magnit/region', [AdminMagnitController::class, 'region']);
    Route::get('/magnit/stores', [AdminMagnitController::class, 'stores']);
    Route::post('/metro/stores/sync', [AdminMetroController::class, 'syncStores']);
    Route::post('/lenta/stores/sync', [AdminLentaController::class, 'syncStores']);
    Route::post('/lenta/categories/sync', [AdminLentaController::class, 'syncCategories']);
    Route::get('/lenta/session', [AdminLentaController::class, 'session']);
    Route::post('/lenta/session', [AdminLentaController::class, 'updateSession']);
    Route::post('/lenta/session/refresh', [AdminLentaController::class, 'refreshSession']);
    Route::get('/parse-runs', [AdminParseRunController::class, 'index']);
    Route::post('/parse-runs', [AdminParseRunController::class, 'store']);
    Route::post('/parse-runs/{parseRun}/cancel', [AdminParseRunController::class, 'cancel']);
    Route::delete('/parse-runs/{parseRun}', [AdminParseRunController::class, 'destroy']);
});
