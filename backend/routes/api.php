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

use App\Http\Controllers\Api\AuthController;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

Route::prefix('admin')->middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
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

    Route::get('/update/check', [\App\Http\Controllers\Api\AdminUpdateController::class, 'check']);
    Route::post('/update/start', [\App\Http\Controllers\Api\AdminUpdateController::class, 'start']);
    Route::get('/update/status', [\App\Http\Controllers\Api\AdminUpdateController::class, 'status']);
    Route::post('/update/rollback', [\App\Http\Controllers\Api\AdminUpdateController::class, 'rollback']);
});
