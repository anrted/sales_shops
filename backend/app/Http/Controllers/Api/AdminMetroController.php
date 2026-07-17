<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StoreProviders\MetroStoreCatalogSync;
use Illuminate\Http\JsonResponse;

class AdminMetroController extends Controller
{
    public function syncStores(MetroStoreCatalogSync $sync): JsonResponse
    {
        return response()->json([
            'item' => $sync->sync(),
        ]);
    }
}
