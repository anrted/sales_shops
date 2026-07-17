<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Services\LentaSessionRefresher;
use App\Services\LentaSessionSettings;
use App\Services\StoreProviders\LentaProvider;
use App\Services\StoreProviders\LentaStoreCatalogSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminLentaController extends Controller
{
    public function syncStores(LentaStoreCatalogSync $sync): JsonResponse
    {
        return response()->json([
            'item' => $sync->sync(),
        ]);
    }

    public function syncCategories(LentaProvider $provider): JsonResponse
    {
        $chain = Chain::query()->where('code', 'lenta')->firstOrFail();

        return response()->json([
            'item' => $provider->syncCategoriesForStoreTypes($chain),
        ]);
    }

    public function session(LentaSessionSettings $settings): JsonResponse
    {
        return response()->json([
            'item' => $settings->current(),
        ]);
    }

    public function updateSession(Request $request, LentaSessionSettings $settings): JsonResponse
    {
        $payload = $request->validate([
            'default_domain' => ['nullable', 'string'],
            'device_id' => ['nullable', 'string'],
            'user_session_id' => ['nullable', 'string'],
            'session_token' => ['nullable', 'string'],
            'raw_cookie_header' => ['nullable', 'string'],
            'qrator_jsr' => ['nullable', 'string'],
            'qrator_jsid' => ['nullable', 'string'],
            'qrator_ssid' => ['nullable', 'string'],
            'utk_marketing_group_token' => ['nullable', 'string'],
            'utk_sss_token' => ['nullable', 'string'],
            'growthbook_user_id' => ['nullable', 'string'],
            'growthbook_experiments' => ['nullable', 'string'],
            'growthbook_cookie_experiments' => ['nullable', 'string'],
            'app_cache_city' => ['nullable', 'string'],
            'iap_uid' => ['nullable', 'string'],
            'browser_user_agent' => ['nullable', 'string'],
        ]);

        return response()->json([
            'item' => $settings->save($payload),
        ]);
    }

    public function refreshSession(Request $request, LentaSessionRefresher $refresher): JsonResponse
    {
        $payload = $request->validate([
            'headed' => ['nullable', 'boolean'],
            'timeout' => ['nullable', 'integer', 'min:30', 'max:240'],
        ]);

        return response()->json([
            'item' => $refresher->refresh(
                (bool) ($payload['headed'] ?? false),
                (int) ($payload['timeout'] ?? 90),
            ),
        ]);
    }
}
