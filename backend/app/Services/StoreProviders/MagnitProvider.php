<?php

namespace App\Services\StoreProviders;

use App\Contracts\StoreProviderInterface;
use App\Data\ProviderResult;
use App\Models\Category;
use App\Models\Chain;
use App\Models\Store;
use App\Services\ParseRunProgress;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class MagnitProvider implements StoreProviderInterface
{
    private const PAGE_SIZE = 50;
    private const MIN_DISCOUNT_PERCENT = 5;

    /** @var array<string, array<string, Category>> */
    private array $categoryCache = [];
    private ?float $lastGoodsResponseAt = null;

    public function __construct(
        private readonly ProviderPersister $persister,
        private readonly ParseRunProgress $progress,
        private readonly MagnitStoreCatalogSync $storeCatalogSync,
    ) {
    }

    public function code(): string
    {
        return 'magnit';
    }

    public function parse(Chain $chain, ?int $cityId = null, ?int $storeId = null): ProviderResult
    {
        $this->progress->update('Магнит: загрузка магазинов');

        $stores = $this->loadStores($chain, $cityId, $storeId);

        if ($stores->isEmpty()) {
            $this->storeCatalogSync->sync();
            $stores = $this->loadStores($chain, $cityId, $storeId);
        }

        $storesCount = 0;
        $productsCount = 0;
        $offersCount = 0;

        foreach ($stores as $storeIndex => $store) {
            $this->progress->ensureNotCancelled();
            $storesCount++;

            $this->progress->update(
                sprintf('Магнит: магазин %d/%d, загрузка категорий', $storesCount, $stores->count()),
                [
                    'stores_count' => $storesCount,
                    'products_count' => $productsCount,
                    'offers_count' => $offersCount,
                ],
            );

            $categories = $this->topLevelCategoriesForStore($chain, $store);

            foreach ($categories as $externalCategoryId => $category) {
                $this->progress->ensureNotCancelled();

                try {
                    $this->streamProducts((string) $store->external_id, (string) $externalCategoryId, function (array $items, int $offset) use ($chain, $store, $category, $storesCount, $stores, &$productsCount, &$offersCount): void {
                    foreach ($items as $item) {
                        $offerData = $this->normalizeOfferData($item);
                        if ($offerData === null) {
                            continue;
                        }

                        $product = $this->persister->upsertProduct($chain, [
                            'external_id' => (string) ($item['productId'] ?? $item['id']),
                            'category_id' => $category->id,
                            'name' => $item['name'],
                            'slug' => $item['seoCode'] ?? null,
                            'image_url' => $this->extractImageUrl($item['gallery'] ?? null),
                            'raw_payload' => $item,
                        ]);
                        $productsCount++;

                        $this->persister->upsertOffer($product, $store, $offerData);
                        $offersCount++;
                    }

                    $this->progress->update(
                        sprintf('Магнит: магазин %d/%d, товары %d, offset %d', $storesCount, $stores->count(), $productsCount, $offset),
                        [
                            'stores_count' => $storesCount,
                            'products_count' => $productsCount,
                            'offers_count' => $offersCount,
                        ],
                    );
                    }, $this->normalizedStoreType($store));
                } catch (RuntimeException $exception) {
                    if (! $this->shouldSkipProductsFailure($exception)) {
                        throw $exception;
                    }

                    $this->reportSkippedProductsFailure(
                        $store,
                        $category,
                        $exception,
                        $storesCount,
                        $stores->count(),
                        $productsCount,
                        $offersCount,
                    );
                }

                $this->pauseBetweenCategories();
            }

            $this->pauseBetweenStores($storeIndex, $stores->count());
        }

        return new ProviderResult($storesCount, $productsCount, $offersCount);
    }

    /** @return array{store_types:int,categories:int} */
    public function syncCategoriesForStoreTypes(Chain $chain, bool $forceRefresh = false): array
    {
        $stores = Store::query()
            ->where('chain_id', $chain->id)
            ->whereNotNull('type')
            ->orderBy('type')
            ->orderBy('external_id')
            ->get()
            ->unique(fn (Store $store): string => (string) $store->type)
            ->values();

        if ($stores->isEmpty()) {
            $this->storeCatalogSync->sync();
            $stores = Store::query()
                ->where('chain_id', $chain->id)
                ->whereNotNull('type')
                ->orderBy('type')
                ->orderBy('external_id')
                ->get()
                ->unique(fn (Store $store): string => (string) $store->type)
                ->values();
        }

        $categoriesCount = 0;
        foreach ($stores as $store) {
            $categoriesCount += count($this->categoriesForStore($chain, $store, $forceRefresh));
        }

        return [
            'store_types' => $stores->count(),
            'categories' => $categoriesCount,
        ];
    }

    /** @return array<string, Category> */
    private function categoriesForStore(Chain $chain, Store $store, bool $forceRefresh = false): array
    {
        $storeType = $this->normalizedStoreType($store);
        $cacheKey = $storeType ?: (string) $store->external_id;
        if (!$forceRefresh && isset($this->categoryCache[$cacheKey])) {
            return $this->categoryCache[$cacheKey];
        }

        if ($forceRefresh) {
            unset($this->categoryCache[$cacheKey]);
        }

        $storedCategories = $forceRefresh ? [] : $this->storedCategoriesForStore($chain, $store);
        if (!$forceRefresh && $storedCategories !== []) {
            return $this->categoryCache[$cacheKey] = $storedCategories;
        }

        $items = $this->fetchCategories((string) $store->external_id);
        $persisted = [];

        foreach ($items as $item) {
            if (empty($item['category_id']) || empty($item['name'])) {
                continue;
            }

            $persisted[(string) $item['category_id']] = $this->persister->upsertCategory($chain, [
                'external_id' => (string) $item['category_id'],
                'store_type' => $storeType,
                'name' => $item['name'],
                'level' => (int) ($item['level'] ?? 0),
            ]);
        }

        foreach ($items as $item) {
            if (empty($item['category_id']) || empty($item['parent_id'])) {
                continue;
            }

            $category = $persisted[(string) $item['category_id']] ?? null;
            $parent = $persisted[(string) $item['parent_id']] ?? null;
            if ($category && $parent) {
                $category->update(['parent_id' => $parent->id, 'level' => (int) ($item['level'] ?? 1)]);
            }
        }

        return $this->categoryCache[$cacheKey] = $persisted;
    }

    /** @return array<string, Category> */
    private function topLevelCategoriesForStore(Chain $chain, Store $store, bool $forceRefresh = false): array
    {
        return array_filter(
            $this->categoriesForStore($chain, $store, $forceRefresh),
            static fn (Category $category): bool => $category->parent_id === null || (int) $category->level === 0,
        );
    }

    /** @return array<string, Category> */
    private function storedCategoriesForStore(Chain $chain, Store $store): array
    {
        $query = Category::query()
            ->where('chain_id', $chain->id);

        $storeType = $this->normalizedStoreType($store);
        if ($storeType !== null) {
            $query->where('store_type', $storeType);
        } else {
            $query->whereNull('store_type');
        }

        return $query
            ->orderBy('level')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (Category $category): string => (string) $category->external_id)
            ->all();
    }

    private function normalizedStoreType(Store $store): ?string
    {
        $type = is_string($store->type) ? trim($store->type) : '';

        return $type !== '' ? $type : null;
    }

    /** @return \Illuminate\Support\Collection<int, Store> */
    private function loadStores(Chain $chain, ?int $cityId, ?int $storeId)
    {
        return Store::query()
            ->where('chain_id', $chain->id)
            ->where('is_active', true)
            ->when($cityId, fn ($query) => $query->where('city_id', $cityId))
            ->when($storeId, fn ($query) => $query->whereKey($storeId))
            ->get()
            ->sort(function (Store $left, Store $right): int {
                $priority = $this->storeTypePriority($left) <=> $this->storeTypePriority($right);
                if ($priority !== 0) {
                    return $priority;
                }

                return [(string) $left->external_id, (string) $left->id] <=> [(string) $right->external_id, (string) $right->id];
            })
            ->values();
    }

    private function storeTypePriority(Store $store): int
    {
        return match ($this->normalizedStoreType($store)) {
            'STORE_TYPE_GM' => 0,
            'STORE_TYPE_MM' => 1,
            default => 2,
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchCategories(string $storeCode): array
    {
        $response = $this->httpClient()
            ->withHeaders($this->catalogHeaders($storeCode))
            ->get((string) config('services.magnit.catalog_url'), [
                'shopCode' => $storeCode,
            ]);

        if (!$response->ok()) {
            throw $this->requestFailed('Magnit catalog page', $response->status(), $response->body());
        }

        $html = $response->body();
        if (!preg_match('/<script[^>]*id="__NUXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $scriptMatches)) {
            throw new RuntimeException('Magnit catalog page does not contain Nuxt data.');
        }

        $dataArray = json_decode($scriptMatches[1], true);
        if (!is_array($dataArray)) {
            throw new RuntimeException('Magnit catalog page contains invalid Nuxt data.');
        }

        $items = $this->extractCategoriesFromNuxtData($dataArray);

        $keyToCategoryId = [];
        $itemsByKey = [];
        $levelForKey = function (string $key, array $seen = []) use (&$itemsByKey, &$levelForKey): int {
            if (isset($seen[$key])) {
                return 0;
            }

            $item = $itemsByKey[$key] ?? null;
            if (!$item || empty($item['parent_key'])) {
                return 0;
            }

            $seen[$key] = true;

            return 1 + $levelForKey((string) $item['parent_key'], $seen);
        };

        foreach ($items as &$item) {
            $keyToCategoryId[$item['key']] = $item['category_id'];
            $itemsByKey[$item['key']] = $item;
        }
        unset($item);

        foreach ($items as &$item) {
            $item['parent_id'] = $item['parent_key'] ? ($keyToCategoryId[$item['parent_key']] ?? null) : null;
            $item['level'] = $levelForKey((string) $item['key']);
        }
        unset($item);

        return $items;
    }

    /** @return array<int, array{key:string,parent_key:?string,category_id:string,name:string,level:int,url:?string,parent_id?:?string}> */
    private function extractCategoriesFromNuxtData(array $dataArray): array
    {
        $items = [];
        $seen = [];
        $walk = function (mixed $node) use (&$walk, &$items, &$seen, $dataArray): void {
            if (!is_array($node)) {
                return;
            }

            if ($this->looksLikeCategoryNode($node)) {
                $item = $this->normalizeCategoryNode($dataArray, $node);
                if ($item !== null) {
                    $identity = $item['key'].'|'.$item['category_id'].'|'.$item['parent_key'];
                    if (!isset($seen[$identity])) {
                        $seen[$identity] = true;
                        $items[] = $item;
                    }
                }
            }

            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };

        $walk($dataArray);

        return array_values(array_filter($items, function (array $item): bool {
            $url = (string) ($item['url'] ?? '');

            return $url !== '' && str_contains($url, '/catalog/') && !str_contains($url, '/promo-catalog/');
        }));
    }

    private function looksLikeCategoryNode(array $node): bool
    {
        return array_key_exists('key', $node)
            && array_key_exists('id', $node)
            && array_key_exists('name', $node)
            && array_key_exists('code', $node)
            && array_key_exists('url', $node)
            && array_key_exists('parentKey', $node)
            && array_key_exists('children', $node);
    }

    /** @return array{key:string,parent_key:?string,category_id:string,name:string,level:int,url:?string}|null */
    private function normalizeCategoryNode(array $dataArray, array $node): ?array
    {
        $key = $this->resolveNuxtScalar($dataArray, $node['key'] ?? null);
        $categoryId = $this->resolveNuxtScalar($dataArray, $node['id'] ?? null);
        $name = $this->resolveNuxtScalar($dataArray, $node['name'] ?? null);
        $url = $this->resolveNuxtScalar($dataArray, $node['url'] ?? null);
        $parentKey = $this->resolveNuxtScalar($dataArray, $node['parentKey'] ?? null);

        if (!is_scalar($key) || !is_scalar($categoryId) || !is_scalar($name)) {
            return null;
        }

        $parentKey = is_scalar($parentKey) ? (string) $parentKey : null;
        if ($parentKey === '' || $parentKey === '0' || $parentKey === 'p0000') {
            $parentKey = null;
        }

        return [
            'key' => (string) $key,
            'parent_key' => $parentKey,
            'category_id' => (string) $categoryId,
            'name' => trim((string) $name),
            'url' => is_scalar($url) ? (string) $url : null,
            'level' => $parentKey === null ? 0 : 1,
        ];
    }

    private function resolveNuxtScalar(array $dataArray, mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return $value;
        }

        if (is_int($value) && array_key_exists($value, $dataArray)) {
            return $this->resolveNuxtScalar($dataArray, $dataArray[$value], $depth + 1);
        }

        return $value;
    }

    private function streamProducts(string $storeCode, string $categoryId, callable $handlePage, ?string $storeType = null): void
    {
        $offset = 0;
        $processedItems = 0;
        $seenOffsets = [];
        $seenPageSignatures = [];
        $maxItems = $this->maxItemsPerCategory();

        while (true) {
            if ($processedItems >= $maxItems) {
                break;
            }

            $this->progress->ensureNotCancelled();
            $this->pauseBeforeNextGoodsRequest();
            $payload = [
                'sort' => [
                    'order' => 'desc',
                    'type' => 'discount',
                ],
                'pagination' => [
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                ],
                'categories' => [(int) $categoryId],
                'includeAdultGoods' => true,
                'storeCode' => $storeCode,
                'storeType' => $this->goodsStoreType($storeType),
                'catalogType' => '1',
            ];

            $headers = $this->goodsHeaders($storeCode, $storeType);
            $this->logGoodsRequest($storeCode, $categoryId, $offset, $storeType, $payload, $headers);

            if ($this->shouldUseCurlBinaryForGoods()) {
                $response = $this->postViaCurlBinary((string) config('services.magnit.goods_url'), $headers, $payload);
            } else {
                try {
                    $response = $this->httpClient()
                        ->acceptJson()
                        ->withHeaders($headers)
                        ->post((string) config('services.magnit.goods_url'), $payload);
                } catch (ConnectionException $exception) {
                    if (!$this->shouldFallbackToCurl($exception)) {
                        throw $exception;
                    }

                    $response = $this->postViaCurlFallback((string) config('services.magnit.goods_url'), $headers, $payload);
                }
            }

            $this->markGoodsResponseReceived();
            $this->logGoodsResponse($storeCode, $categoryId, $offset, $response);

            if (!$response->ok()) {
                throw $this->requestFailed('Magnit goods API', $response->status(), $response->body());
            }

            $items = $response->json('items', []);
            if (!is_array($items) || !$items) {
                break;
            }

            $pageItems = array_values(array_filter($items, static fn ($item): bool => is_array($item)));
            if ($pageItems === []) {
                break;
            }

            $remainingItems = $maxItems - $processedItems;
            if ($remainingItems <= 0) {
                break;
            }

            if (count($pageItems) > $remainingItems) {
                $pageItems = array_slice($pageItems, 0, $remainingItems);
            }

            $handlePage($pageItems, $offset);
            $processedItems += count($pageItems);

            $pagination = $response->json('pagination', []);
            $hasMore = is_array($pagination) ? (bool) ($pagination['hasMore'] ?? false) : count($pageItems) === self::PAGE_SIZE;
            $totalCount = is_array($pagination) && isset($pagination['totalCount']) && is_numeric($pagination['totalCount'])
                ? (int) $pagination['totalCount']
                : null;
            $nextOffset = is_array($pagination) && isset($pagination['nextOffset']) && is_numeric($pagination['nextOffset'])
                ? (int) $pagination['nextOffset']
                : null;

            if ($totalCount !== null && ($offset + count($pageItems)) >= $totalCount) {
                break;
            }

            if ($processedItems >= $maxItems) {
                break;
            }

            if (!$hasMore || count($pageItems) < self::PAGE_SIZE) {
                break;
            }

            $pageSignature = $this->pageSignature($pageItems);
            if ($pageSignature !== null) {
                if (isset($seenPageSignatures[$pageSignature])) {
                    Log::warning('magnit.pagination_stopped_repeated_page', [
                        'store_code' => $storeCode,
                        'category_id' => $categoryId,
                        'offset' => $offset,
                    ]);

                    break;
                }

                $seenPageSignatures[$pageSignature] = true;
            }

            $seenOffsets[$offset] = true;
            $nextOffset = $nextOffset ?? ($offset + self::PAGE_SIZE);
            if ($nextOffset <= $offset || isset($seenOffsets[$nextOffset])) {
                Log::warning('magnit.pagination_stopped_repeated_offset', [
                    'store_code' => $storeCode,
                    'category_id' => $categoryId,
                    'offset' => $offset,
                    'next_offset' => $nextOffset,
                ]);

                break;
            }

            $offset = $nextOffset;
            usleep(random_int($this->pageDelayMinMicros(), $this->pageDelayMaxMicros()));
        }
    }

    private function maxItemsPerCategory(): int
    {
        return max(1, (int) config('services.magnit.max_items_per_category', 150));
    }

    /** @param array<int, array<string, mixed>> $items */
    private function pageSignature(array $items): ?string
    {
        $ids = [];

        foreach ($items as $item) {
            $id = $item['productId'] ?? $item['id'] ?? null;
            if (!is_scalar($id) || $id === '') {
                continue;
            }

            $ids[] = (string) $id;
        }

        if ($ids === []) {
            return null;
        }

        return md5(implode('|', $ids));
    }

    private function httpClient(): PendingRequest
    {
        $retryAttempts = max(1, (int) config('services.magnit.retry_attempts', 3));
        $retryBackoffMs = max(0, (int) config('services.magnit.retry_backoff_ms', 1200));

        return Http::timeout((int) config('services.magnit.timeout', 30))
            ->connectTimeout((int) config('services.magnit.connect_timeout', 20))
            ->retry(
                $retryAttempts,
                $retryBackoffMs,
                function (\Exception $exception): bool {
                    return $exception instanceof ConnectionException;
                },
                throw: false,
            )
            ->withOptions([
                'version' => 1.1,
                'curl' => [
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_FORBID_REUSE => false,
                    CURLOPT_FRESH_CONNECT => false,
                ],
            ]);
    }

    private function shouldFallbackToCurl(ConnectionException $exception): bool
    {
        if (!config('services.magnit.curl_fallback_enabled', true)) {
            return false;
        }

        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'curl error 35')
            || str_contains($message, 'tls connect error')
            || str_contains($message, 'unexpected eof while reading');
    }

    private function shouldUseCurlBinaryForGoods(): bool
    {
        return (bool) config('services.magnit.goods_use_curl_binary', false);
    }

    /** @param array<string, string> $headers */
    private function postViaCurlBinary(string $url, array $headers, array $payload): Response
    {
        $command = [(string) config('services.magnit.curl_binary', 'curl.exe')];

        $command[] = '--silent';
        $command[] = '--show-error';
        $command[] = '--location';
        $command[] = '--write-out';
        $command[] = "\n__CURL_STATUS__:%{http_code}";
        $command[] = $url;

        foreach ($headers as $name => $value) {
            $command[] = '--header';
            $command[] = $name.': '.$value;
        }

        $command[] = '--data';
        $command[] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $process = new Process($command, base_path(), null, null, (int) config('services.magnit.timeout', 30));
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Magnit curl binary request failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $output = $process->getOutput();
        $marker = "\n__CURL_STATUS__:";
        $position = strrpos($output, $marker);

        if ($position === false) {
            throw new RuntimeException('Magnit curl binary request did not return an HTTP status marker.');
        }

        $body = substr($output, 0, $position);
        $status = (int) trim(substr($output, $position + strlen($marker)));

        return new Response(new Psr7Response($status, [], $body));
    }

    /** @param array<string, string> $headers */
    private function postViaCurlFallback(string $url, array $headers, array $payload): Response
    {
        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name.': '.$value;
        }

        $responseHeaders = [];
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Magnit native cURL fallback could not initialize curl handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_TIMEOUT => (int) config('services.magnit.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => (int) config('services.magnit.connect_timeout', 20),
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
            CURLOPT_SSL_ENABLE_ALPN => false,
            CURLOPT_SSL_ENABLE_NPN => false,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }

                return strlen($headerLine);
            },
        ]);

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('Magnit native cURL fallback failed: '.$error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new Response(new Psr7Response($status, $this->headersFromCurlResponse($responseHeaders), $responseBody));
    }

    /** @param array<int, string> $headerLines
     *  @return array<string, string>
     */
    private function headersFromCurlResponse(array $headerLines): array
    {
        $headers = [];

        foreach ($headerLines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '') {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @return array<string, mixed>|null */
    private function normalizeOfferData(array $item): ?array
    {
        if (empty($item['productId']) && empty($item['id'])) {
            return null;
        }

        if (empty($item['name']) || !is_numeric($item['price'] ?? null)) {
            return null;
        }

        $price = round(((float) $item['price']) / 100, 2);
        if ($price <= 0) {
            return null;
        }

        $promotion = is_array($item['promotion'] ?? null) ? $item['promotion'] : [];
        $discountPercent = isset($promotion['discountPercent']) && is_numeric($promotion['discountPercent'])
            ? (float) $promotion['discountPercent']
            : 0.0;

        if ($discountPercent < self::MIN_DISCOUNT_PERCENT) {
            return null;
        }

        $oldPrice = $discountPercent > 0 && $discountPercent < 100
            ? round($price / (1 - ($discountPercent / 100)), 2)
            : $price;

        return [
            'price' => $price,
            'old_price' => $oldPrice,
            'stock' => isset($item['quantity']) && is_numeric($item['quantity']) ? (int) $item['quantity'] : null,
            'discount_percent' => round($discountPercent, 2),
            'profit' => round(max(0, $oldPrice - $price), 2),
            'ends_at' => $promotion['endDate'] ?? null,
        ];
    }

    private function extractImageUrl(mixed $gallery): ?string
    {
        if (is_string($gallery)) {
            return trim($gallery) !== '' ? trim($gallery) : null;
        }

        if (!is_array($gallery)) {
            return null;
        }

        $first = $gallery[0] ?? null;
        if (is_string($first)) {
            return trim($first) !== '' ? trim($first) : null;
        }

        return is_array($first) ? ($first['url'] ?? null) : null;
    }

    private function catalogHeaders(string $storeCode): array
    {
        return $this->baseHeaders([
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Referer' => rtrim((string) config('services.magnit.catalog_url'), '/').'?shopCode='.$storeCode,
        ], $storeCode);
    }

    private function goodsHeaders(string $storeCode, ?string $storeType): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Accept-Language' => (string) config('services.magnit.accept_language', 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7'),
        ];

        $deviceId = trim((string) config('services.magnit.device_id', ''));
        if ($deviceId !== '') {
            $headers['x-device-id'] = $deviceId;
        }

        $baggage = trim((string) config('services.magnit.baggage', ''));
        if ($baggage !== '') {
            $headers['baggage'] = $baggage;
        }

        return $this->baseHeaders($headers, $storeCode, $storeType, false);
    }

    /** @param array<string, string> $headers */
    private function baseHeaders(array $headers, string $storeCode, ?string $storeType = null, bool $includeCookie = true): array
    {
        $headers = array_merge([
            'User-Agent' => (string) config('services.magnit.user_agent', 'Mozilla/5.0'),
        ], $headers);

        if ($includeCookie) {
            $cookieHeader = $this->cookieHeader($storeCode, $storeType);
            if ($cookieHeader !== null) {
                $headers['Cookie'] = $cookieHeader;
            }
        }

        return $headers;
    }

    private function cookieHeader(string $storeCode, ?string $storeType = null): ?string
    {
        $cookies = [];
        $rawCookieHeader = trim((string) config('services.magnit.raw_cookie_header', ''));
        if ($rawCookieHeader !== '') {
            foreach (preg_split('/;\s*/', trim($rawCookieHeader, "; \t\r\n")) ?: [] as $part) {
                if ($part === '') {
                    continue;
                }

                [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
                $name = trim($name);
                if ($name === '') {
                    continue;
                }

                $cookies[$name] = $value;
            }
        }

        $cookies['nmg_dt'] = $this->cookieValue((string) config('services.magnit.delivery_type', 'DELIVERY_TYPE_PICKUP'));
        $cookies['shopCode'] = $this->cookieValue('"'.$storeCode.'"');

        $shortStoreType = $this->shortStoreType($storeType);
        if ($shortStoreType !== null) {
            $cookies['x_shop_type'] = $this->cookieValue($shortStoreType);
        }

        $deviceId = trim((string) config('services.magnit.device_id', ''));
        if ($deviceId !== '') {
            $cookies['mg_udi'] = $this->cookieValue($deviceId);
        }

        $parts = [];
        foreach ($cookies as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $parts[] = $name.'='.$value;
        }

        return $parts === [] ? null : implode('; ', $parts);
    }

    private function goodsStoreType(?string $storeType): string
    {
        $configured = trim((string) config('services.magnit.goods_store_type', ''));
        if ($configured !== '') {
            return $configured;
        }

        $storeType = trim((string) $storeType);

        return $storeType !== '' ? $storeType : '1';
    }

    private function shortStoreType(?string $storeType): ?string
    {
        $storeType = trim((string) $storeType);
        if ($storeType === '') {
            return null;
        }

        return Str::after($storeType, 'STORE_TYPE_');
    }

    private function cookieValue(string $value): string
    {
        return rawurlencode($value);
    }

    private function pauseBetweenCategories(): void
    {
        $delayMs = max(0, (int) config('services.magnit.category_delay_ms', 900));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function pauseBetweenStores(int $storeIndex, int $storesCount): void
    {
        if ($storeIndex >= $storesCount - 1) {
            return;
        }

        $delayMs = max(0, (int) config('services.magnit.store_delay_ms', 2500));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }
    }

    private function pageDelayMinMicros(): int
    {
        return max(0, (int) config('services.magnit.page_delay_min_ms', 900)) * 1000;
    }

    private function goodsRequestDelayMicros(): int
    {
        return max(0, (int) config('services.magnit.goods_request_delay_ms', 2000)) * 1000;
    }

    private function pageDelayMaxMicros(): int
    {
        $min = $this->pageDelayMinMicros();
        $max = max(0, (int) config('services.magnit.page_delay_max_ms', 1800)) * 1000;

        return max($min, $max);
    }

    private function pauseBeforeNextGoodsRequest(): void
    {
        if ($this->lastGoodsResponseAt === null) {
            return;
        }

        $delayMicros = $this->goodsRequestDelayMicros();
        if ($delayMicros <= 0) {
            return;
        }

        $elapsedMicros = (int) round((microtime(true) - $this->lastGoodsResponseAt) * 1000000);
        if ($elapsedMicros >= $delayMicros) {
            return;
        }

        usleep($delayMicros - $elapsedMicros);
    }

    private function markGoodsResponseReceived(): void
    {
        $this->lastGoodsResponseAt = microtime(true);
    }

    private function requestFailed(string $requestName, int $status, string $body): RuntimeException
    {
        $message = $requestName.' returned HTTP '.$status;
        if ($status === 403 && preg_match('/Transaction ID:\s*([A-Z0-9-]+)/i', $body, $matches)) {
            $message .= ' (blocked by Magnit WAF, transaction '.$matches[1].')';
        }

        return new RuntimeException($message);
    }

    /** @param array<string, mixed> $payload
     *  @param array<string, string> $headers
     */
    private function logGoodsRequest(string $storeCode, string $categoryId, int $offset, ?string $storeType, array $payload, array $headers): void
    {
        Log::debug('magnit.goods_request', [
            'store_code' => $storeCode,
            'category_id' => $categoryId,
            'offset' => $offset,
            'store_type' => $storeType,
            'payload' => $payload,
            'headers' => $this->sanitizedHeaders($headers),
        ]);
    }

    private function logGoodsResponse(string $storeCode, string $categoryId, int $offset, Response $response): void
    {
        Log::debug('magnit.goods_response', [
            'store_code' => $storeCode,
            'category_id' => $categoryId,
            'offset' => $offset,
            'status' => $response->status(),
            'body_snippet' => mb_substr($response->body(), 0, 500),
        ]);
    }

    /** @param array<string, string> $headers
     *  @return array<string, string>
     */
    private function sanitizedHeaders(array $headers): array
    {
        $sanitized = $headers;

        if (isset($sanitized['Cookie'])) {
            $sanitized['Cookie'] = $this->maskCookieHeader($sanitized['Cookie']);
        }

        return $sanitized;
    }

    private function maskCookieHeader(string $cookieHeader): string
    {
        $visibleCookies = ['shopCode', 'x_shop_type', 'nmg_dt', 'mg_udi'];
        $maskedParts = [];

        foreach (preg_split('/;\s*/', trim($cookieHeader, "; \t\r\n")) ?: [] as $part) {
            if ($part === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $maskedParts[] = in_array($name, $visibleCookies, true)
                ? $name.'='.$value
                : $name.'=***';
        }

        return implode('; ', $maskedParts);
    }

    private function shouldSkipProductsFailure(RuntimeException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'magnit goods api returned http 403')
            || str_contains($message, 'blocked by magnit waf');
    }

    private function reportSkippedProductsFailure(
        Store $store,
        Category $category,
        RuntimeException $exception,
        int $storesCount,
        int $storesTotal,
        int $productsCount,
        int $offersCount,
    ): void {
        Log::warning('magnit.products_skipped', [
            'store_id' => $store->id,
            'store_external_id' => $store->external_id,
            'store_type' => $store->type,
            'category_id' => $category->id,
            'category_external_id' => $category->external_id,
            'message' => $exception->getMessage(),
        ]);

        $this->progress->update(
            sprintf('Magnit: store %d/%d, category %s skipped due to WAF block', $storesCount, $storesTotal, (string) $category->external_id),
            [
                'stores_count' => $storesCount,
                'products_count' => $productsCount,
                'offers_count' => $offersCount,
            ],
        );
    }
}
