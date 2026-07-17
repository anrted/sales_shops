<?php

namespace App\Services\StoreProviders;

use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class LentaApiClient
{
    private const MIN_CATALOG_ITEMS_INTERVAL_MS = 3200;

    private ?string $sessionToken = null;

    private string $deviceId;

    private string $userSessionId;

    private CookieJar $cookieJar;

    private bool $bootstrapped = false;

    private ?float $lastCatalogItemsRequestAt = null;

    public function __construct()
    {
        $this->deviceId = (string) (config('services.lenta.device_id') ?: Str::uuid());
        $this->userSessionId = (string) (config('services.lenta.user_session_id') ?: Str::uuid());
        $this->cookieJar = new CookieJar();
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchRegions(): array
    {
        $url = (string) config('services.lenta.regions_url');
        $this->logStep('region.list.start', ['url' => $url]);

        if (app()->environment('testing')) {
            $response = $this->request()->acceptJson()->get($url);
            if (! $response->ok()) {
                $this->throwRequestException('region/list', $response->status(), $response->body(), [
                    'url' => $url,
                ]);
            }

            $regions = $response->json('regions', []);
        } else {
            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'GET',
                $url,
                $this->apiHeaders(),
            );
            $this->storeResponseCookies($responseHeaders);

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException('region/list', $status, $body, [
                    'url' => $url,
                ]);
            }

            $payload = json_decode($body, true);
            $regions = is_array($payload) ? ($payload['regions'] ?? []) : [];
        }

        if (!is_array($regions)) {
            throw new RuntimeException('Lenta regions API returned invalid data.');
        }

        $this->logStep('region.list.success', ['count' => count($regions)]);

        return array_values(array_filter($regions, static fn ($region): bool => is_array($region)));
    }

    /** @param array<string, mixed> $region
     *  @return array<int, array<string, mixed>>
     */
    public function fetchPickupStoresForRegion(array $region): array
    {
        $slug = is_string($region['slug'] ?? null) ? $region['slug'] : null;
        if ($slug === null || $slug === '') {
            return [];
        }

        $url = (string) config('services.lenta.pickup_stores_url');
        $this->logStep('stores.pickup.start', ['region_slug' => $slug, 'url' => $url]);

        if (app()->environment('testing')) {
            $response = $this->request($slug)
                ->acceptJson()
                ->withBody('{}', 'application/json')
                ->send('POST', $url);

            if (! $response->ok()) {
                $this->throwRequestException('stores/pickup/search', $response->status(), $response->body(), [
                    'region_slug' => $slug,
                    'url' => $url,
                ]);
            }

            $stores = $response->json('items', []);
        } else {
            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'POST',
                $url,
                $this->apiHeaders($slug, true),
                '{}',
            );
            $this->storeResponseCookies($responseHeaders);

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException('stores/pickup/search', $status, $body, [
                    'region_slug' => $slug,
                    'url' => $url,
                ]);
            }

            $payload = json_decode($body, true);
            $stores = is_array($payload) ? ($payload['items'] ?? []) : [];
        }

        if (!is_array($stores)) {
            throw new RuntimeException('Lenta pickup stores API returned invalid data.');
        }

        $this->logStep('stores.pickup.success', ['region_slug' => $slug, 'count' => count($stores)]);

        return array_values(array_filter($stores, static fn ($store): bool => is_array($store)));
    }

    public function selectPickupStore(string $regionSlug, int $storeId, array $storeMeta = []): void
    {
        $url = (string) config('services.lenta.delivery_mode_url');
        $this->logStep('delivery.mode.start', ['region_slug' => $regionSlug, 'store_id' => $storeId, 'url' => $url]);

        $payload = [
            'type' => 'pickup',
            'storeId' => $storeId,
        ];

        if (app()->environment('testing')) {
            $response = $this->request($regionSlug)
                ->acceptJson()
                ->post($url, $payload);

            if (!$response->ok()) {
                $this->throwRequestException('delivery/mode/set', $response->status(), $response->body(), [
                    'region_slug' => $regionSlug,
                    'store_id' => $storeId,
                    'url' => $url,
                ]);
            }
        } else {
            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'POST',
                $url,
                $this->jsonApiHeaders($regionSlug, true),
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
            $this->storeResponseCookies($responseHeaders);

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException('delivery/mode/set', $status, $body, [
                    'region_slug' => $regionSlug,
                    'store_id' => $storeId,
                    'url' => $url,
                ]);
            }
        }

        $this->applyPickupCookies($regionSlug, $storeId, $storeMeta);

        $this->logStep('delivery.mode.success', ['region_slug' => $regionSlug, 'store_id' => $storeId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchCategories(string $regionSlug): array
    {
        $url = (string) config('services.lenta.categories_url');
        $this->logStep('categories.start', ['region_slug' => $regionSlug, 'url' => $url]);

        if (app()->environment('testing')) {
            $response = $this->request($regionSlug)
                ->acceptJson()
                ->get($url, [
                    'timestamp' => (string) now()->valueOf(),
                ]);

            if (! $response->ok()) {
                $this->throwRequestException('catalog/categories', $response->status(), $response->body(), [
                    'region_slug' => $regionSlug,
                    'url' => $url,
                ]);
            }

            $items = $response->json('categories', []);
        } else {
            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'GET',
                $url.'?timestamp='.(string) now()->valueOf(),
                $this->apiHeaders($regionSlug, true),
            );
            $this->storeResponseCookies($responseHeaders);

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException('catalog/categories', $status, $body, [
                    'region_slug' => $regionSlug,
                    'url' => $url,
                ]);
            }

            $payload = json_decode($body, true);
            $items = is_array($payload) ? ($payload['categories'] ?? []) : [];
        }

        if (!is_array($items)) {
            throw new RuntimeException('Lenta categories API returned invalid data.');
        }

        $this->logStep('categories.success', ['region_slug' => $regionSlug, 'count' => count($items)]);

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchItemsPage(string $regionSlug, int $categoryId, int $limit, int $offset, ?string $categorySlug = null): array
    {
        $url = (string) config('services.lenta.items_url');
        $this->logStep('items.start', [
            'region_slug' => $regionSlug,
            'category_id' => $categoryId,
            'limit' => $limit,
            'offset' => $offset,
            'category_slug' => $categorySlug,
            'url' => $url,
        ]);

        $payload = [
            'categoryId' => $categoryId,
            'filters' => [
                'checkbox' => [],
                'multicheckbox' => [],
                'range' => [],
            ],
            'sort' => [
                'type' => 'sale',
                'order' => 'desc',
            ],
            'limit' => $limit,
            'offset' => $offset,
        ];

        $context = [
            'region_slug' => $regionSlug,
            'category_id' => $categoryId,
            'limit' => $limit,
            'offset' => $offset,
            'category_slug' => $categorySlug,
            'url' => $url,
        ];

        if (app()->environment('testing')) {
            $response = $this->postWithRateLimitRetry($regionSlug, $url, $payload, 'catalog/items', $context, $this->jsonApiHeaders($regionSlug, true, $this->catalogCategoryReferer($categoryId, $categorySlug)));

            if (!$response->ok()) {
                $this->throwRequestException('catalog/items', $response->status(), $response->body(), $context);
            }

            $items = $response->json('items', []);
        } else {
            $items = $this->postCurlJsonWithRateLimitRetry(
                $regionSlug,
                $url,
                $payload,
                'catalog/items',
                $context,
                $this->catalogItemsHeaders($regionSlug, $categoryId, $categorySlug),
            );
        }

        if (!is_array($items)) {
            throw new RuntimeException('Lenta items API returned invalid data.');
        }

        $this->logStep('items.success', [
            'region_slug' => $regionSlug,
            'category_id' => $categoryId,
            'limit' => $limit,
            'offset' => $offset,
            'category_slug' => $categorySlug,
            'count' => count($items),
        ]);

        return array_values(array_filter($items, static fn ($item): bool => is_array($item)));
    }

    /** @return array<int, array<string, mixed>> */
    private function postCurlJsonWithRateLimitRetry(?string $regionSlug, string $url, array $payload, string $stage, array $context, array $headers): array
    {
        $attempts = max(1, (int) config('services.lenta.retry_429_attempts', 3));
        $bodyPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($stage === 'catalog/items') {
            $this->logStep('items.request', $context + [
                'cookie_names' => $this->cookieNamesFromHeader((string) ($headers['cookie'] ?? '')),
                'header_keys' => array_keys($headers),
                'sessiontoken_prefix' => substr((string) ($headers['sessiontoken'] ?? ''), 0, 8),
            ]);
        }

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($stage === 'catalog/items') {
                $this->throttleCatalogItemsRequests();
                $headers['sessiontoken'] = $this->sessionToken();
                $headers['cookie'] = $this->cookieHeader();
            }

            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'POST',
                $url,
                $headers,
                $bodyPayload,
            );
            if ($stage === 'catalog/items') {
                $this->lastCatalogItemsRequestAt = microtime(true);
            }
            $this->storeResponseCookies($responseHeaders);

            if ($status === 429 && $attempt < $attempts) {
                $this->refreshSessionAfterRateLimit($stage);
                $delayMs = min(60_000, 10_000 * $attempt);
                $this->logStep('rate_limit.retry', $context + [
                    'stage' => $stage,
                    'attempt' => $attempt,
                    'delay_ms' => $delayMs,
                ]);
                usleep($delayMs * 1000);

                continue;
            }

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException($stage, $status, $body, $context);
            }

            $decoded = json_decode($body, true);

            return is_array($decoded) && is_array($decoded['items'] ?? null)
                ? array_values(array_filter($decoded['items'], static fn ($item): bool => is_array($item)))
                : [];
        }

        return [];
    }

    private function throttleCatalogItemsRequests(): void
    {
        if (app()->environment('testing') || $this->lastCatalogItemsRequestAt === null) {
            return;
        }

        $elapsedMs = (int) round((microtime(true) - $this->lastCatalogItemsRequestAt) * 1000);
        $remainingMs = self::MIN_CATALOG_ITEMS_INTERVAL_MS - $elapsedMs;
        if ($remainingMs <= 0) {
            return;
        }

        usleep($remainingMs * 1000);
    }

    private function refreshSessionAfterRateLimit(string $stage): void
    {
        if (app()->environment('testing') || $stage !== 'catalog/items' || $this->rawCookieHeader() === null) {
            return;
        }

        $this->sessionToken = null;
        $this->sessionToken();
    }

    private function postWithRateLimitRetry(?string $regionSlug, string $url, array $payload, string $stage, array $context, ?array $headers = null): \Illuminate\Http\Client\Response
    {
        $attempts = max(1, (int) config('services.lenta.retry_429_attempts', 3));
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = Http::timeout((int) config('services.lenta.timeout', 30))
                ->withOptions($this->httpOptions())
                ->withHeaders($headers ?? $this->apiHeaders($regionSlug, true))
                ->acceptJson()
                ->post($url, $payload);

            if ($response->status() !== 429 || $attempt === $attempts) {
                return $response;
            }

            $delayMs = min(8_000, 900 * $attempt);
            $this->logStep('rate_limit.retry', $context + [
                'stage' => $stage,
                'attempt' => $attempt,
                'delay_ms' => $delayMs,
            ]);
            usleep($delayMs * 1000);
        }

        return $response;
    }

    private function sessionToken(): string
    {
        if ($this->sessionToken !== null) {
            return $this->sessionToken;
        }

        $this->ensureManualSessionConfiguration();
        $this->bootstrapSession();

        $configuredToken = $this->configuredSessionToken();
        if ($configuredToken !== null && $this->rawCookieHeader() === null) {
            $this->sessionToken = $configuredToken;
            $this->seedSessionCookies($this->sessionToken);
            $this->logStep('session.get.skipped', [
                'reason' => 'configured_session_token',
                'url' => (string) config('services.lenta.session_url'),
                'token_prefix' => substr($this->sessionToken, 0, 8),
            ]);

            return $this->sessionToken;
        }

        $this->logStep('session.get.start', [
            'url' => (string) config('services.lenta.session_url'),
            'user_session_id' => $this->userSessionId,
            'device_id' => $this->deviceId,
            'domain' => (string) config('services.lenta.default_domain'),
            'manual_mode' => true,
        ]);

        $payload = $this->sessionRequestPayload();
        $headers = [
            'content-type' => 'application/x-www-form-urlencoded',
            'cookie' => $this->sessionCookieHeader(),
        ];

        $this->logStep('session.get.request', [
            'url' => (string) config('services.lenta.session_url'),
            'cookie_names' => $this->cookieNamesFromHeader($headers['cookie']),
            'header_keys' => array_keys($headers),
            'payload' => $payload,
        ]);

        if (app()->environment('testing')) {
            $response = Http::timeout((int) config('services.lenta.timeout', 30))
                ->withOptions($this->httpOptions())
                ->withHeaders($headers)
                ->withBody($payload, 'application/x-www-form-urlencoded')
                ->send('POST', (string) config('services.lenta.session_url'));

            if (!$response->ok()) {
                $this->throwRequestException('sessionGet', $response->status(), $response->body(), [
                    'payload' => $payload,
                    'device_id' => $this->deviceId,
                    'user_session_id' => $this->userSessionId,
                ]);
            }

            $data = $response->json();
        } else {
            ['status' => $status, 'body' => $body, 'headers' => $responseHeaders] = $this->sendCurlRequest(
                'POST',
                (string) config('services.lenta.session_url'),
                $headers,
                $payload,
            );

            $this->storeResponseCookies($responseHeaders);

            if ($status < 200 || $status >= 300) {
                $this->throwRequestException('sessionGet', $status, $body, [
                    'payload' => $payload,
                    'device_id' => $this->deviceId,
                    'user_session_id' => $this->userSessionId,
                ]);
            }

            $data = json_decode($body, true);
            if (! is_array($data)) {
                throw new RuntimeException('Lenta session API returned invalid JSON body.');
            }
        }

        $token = data_get($data, 'Body.SessionToken') ?? data_get($data, 'Head.SessionToken');
        if (!is_string($token) || trim($token) === '') {
            $errorDescription = data_get($data, 'Body.ErrorList.0.Description')
                ?? data_get($data, 'Body.ErrorList.0.Message')
                ?? null;

            if (is_string($errorDescription) && trim($errorDescription) !== '') {
                throw new RuntimeException('Lenta session API did not return a session token: '.trim($errorDescription));
            }

            throw new RuntimeException('Lenta session API did not return a session token.');
        }

        $this->sessionToken = trim($token);
        $this->seedSessionCookies($this->sessionToken);
        $this->logStep('session.get.success', [
            'url' => (string) config('services.lenta.session_url'),
            'token_prefix' => substr($this->sessionToken, 0, 8),
            'payload_request_id' => data_get($this->decodedSessionPayload($payload), 'Head.RequestId'),
        ]);

        return $this->sessionToken;
    }

    private function request(?string $regionSlug = null): PendingRequest
    {
        return Http::timeout((int) config('services.lenta.timeout', 30))
            ->withOptions($this->httpOptions())
            ->withHeaders($this->apiHeaders($regionSlug, true));
    }

    private function bootstrapSession(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->seedCookies();
        $this->seedRawCookies();
        $this->logStep('bootstrap.skipped', [
            'reason' => 'manual_session_configuration',
            'cookie_names' => array_map(static fn (array $cookie) => $cookie['Name'] ?? null, $this->cookieJar->toArray()),
        ]);
        $this->bootstrapped = true;
    }

    private function seedCookies(): void
    {
        $host = parse_url((string) config('services.lenta.web_url'), PHP_URL_HOST) ?: 'lenta.com';

        foreach ([
            'Utk_DvcGuid' => $this->deviceId,
            'UserSessionId' => $this->userSessionId,
            'App_Cache_CitySlug' => (string) config('services.lenta.default_domain'),
            'App_Cache_City' => config('services.lenta.app_cache_city'),
            'App_Cache_MissionAddressMode' => '{"t":"pickup","ids":true}',
            'App_Cache_MPK' => (string) config('services.lenta.marketing_partner_key'),
            'GrowthBook_user_id' => config('services.lenta.growthbook_user_id'),
            'GrowthBook_experiments' => config('services.lenta.growthbook_experiments'),
            'GrowthBook_Cookie_Experiments' => config('services.lenta.growthbook_cookie_experiments'),
            'Utk_MrkGrpTkn' => config('services.lenta.utk_marketing_group_token'),
            'Utk_SssTkn' => config('services.lenta.utk_sss_token'),
            'qrator_ssid' => config('services.lenta.qrator_ssid'),
            'qrator_jsr' => config('services.lenta.qrator_jsr'),
            'qrator_jsid' => config('services.lenta.qrator_jsid'),
            'User_Agent' => $this->browserUserAgent(),
            'Is_Search_Bot' => 'false',
            'iap.uid' => $this->iapUid(),
            'agree_with_cookie' => (string) config('services.lenta.agree_with_cookie', 'true'),
        ] as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $this->cookieJar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => $host,
                'Path' => '/',
                'Discard' => false,
            ]));
        }
    }

    private function seedRawCookies(): void
    {
        $rawCookieHeader = $this->rawCookieHeader();
        if ($rawCookieHeader === null) {
            return;
        }

        $host = parse_url((string) config('services.lenta.web_url'), PHP_URL_HOST) ?: 'lenta.com';
        foreach (explode(';', $rawCookieHeader) as $part) {
            $part = trim($part);
            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $part, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $this->cookieJar->setCookie(new SetCookie([
                'Name' => $name,
                'Value' => trim($value),
                'Domain' => $host,
                'Path' => '/',
                'Discard' => false,
            ]));
        }
    }

    private function browserUserAgent(): string
    {
        $configured = config('services.lenta.browser_user_agent');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/148.0.0.0 Safari/537.36';
    }

    private function iapUid(): string
    {
        $configured = config('services.lenta.iap_uid');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : md5($this->deviceId.$this->userSessionId);
    }

    /** @return array<string, mixed> */
    private function httpOptions(): array
    {
        return [
            'cookies' => $this->cookieJar,
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                CURLOPT_ENCODING => '',
            ],
        ];
    }

    private function cookieHeader(): string
    {
        $cookies = [];
        foreach ($this->cookieJar->toArray() as $cookie) {
            $name = $cookie['Name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $value = (string) ($cookie['Value'] ?? '');
            $cookies[$name] = $value;
        }

        foreach ([
            'PassportRefreshToken' => '',
            'PassportAccessToken' => '',
            'PassportExpiresIn' => '',
        ] as $name => $value) {
            if (! array_key_exists($name, $cookies)) {
                $cookies[$name] = $value;
            }
        }

        return collect($cookies)
            ->map(static fn (string $value, string $name): string => $name.'='.$value)
            ->implode('; ');
    }

    private function seedSessionCookies(string $sessionToken): void
    {
        $host = parse_url((string) config('services.lenta.web_url'), PHP_URL_HOST) ?: 'lenta.com';

        $cookies = [
            'Utk_SessionToken' => $sessionToken,
        ];

        // Some Lenta anti-bot setups expect Utk_SssTkn to be present even when
        // it was not supplied manually. Fall back to the freshly received
        // session token so follow-up catalog requests keep the same auth shape.
        if ($this->cookieValue('Utk_SssTkn') === null || $this->cookieValue('Utk_SssTkn') === '') {
            $cookies['Utk_SssTkn'] = $sessionToken;
        }

        foreach ($cookies as $name => $value) {
            $this->cookieJar->setCookie(new SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => $host,
                'Path' => '/',
                'Discard' => false,
            ]));
        }
    }

    private function sessionCookieHeader(): string
    {
        $rawCookieHeader = $this->rawCookieHeader();
        if ($rawCookieHeader !== null) {
            return $rawCookieHeader;
        }

        $cookies = [];

        foreach ([
            'Utk_MrkGrpTkn',
            'Utk_SssTkn',
            'qrator_ssid',
            'qrator_jsr',
            'qrator_jsid',
        ] as $name) {
            $value = $this->cookieValue($name);
            if ($value === null || $value === '') {
                continue;
            }

            $cookies[] = $name.'='.$value;
        }

        return implode('; ', $cookies);
    }

    /** @return array<int, string> */
    private function cookieNamesFromHeader(string $cookieHeader): array
    {
        if (trim($cookieHeader) === '') {
            return [];
        }

        return collect(explode(';', $cookieHeader))
            ->map(static fn (string $item): string => trim($item))
            ->filter()
            ->map(static fn (string $item): string => trim(strtok($item, '=') ?: ''))
            ->filter()
            ->values()
            ->all();
    }

    private function cookieValue(string $name): ?string
    {
        foreach ($this->cookieJar->toArray() as $cookie) {
            if (($cookie['Name'] ?? null) !== $name) {
                continue;
            }

            return (string) ($cookie['Value'] ?? '');
        }

        return null;
    }

    /** @return array<string, string> */
    private function apiHeaders(?string $regionSlug = null, bool $includeOrigin = false, ?string $referer = null): array
    {
        $headers = [
            'accept' => 'application/json',
            'client' => (string) config('services.lenta.client'),
            'x-organization-id' => '',
            'x-retail-brand' => (string) config('services.lenta.retail_brand'),
            'x-platform' => (string) config('services.lenta.platform'),
            'x-device-os' => (string) config('services.lenta.device_os'),
            'x-device-os-version' => '12.4.8',
            'x-device-web-platform' => (string) config('services.lenta.device_web_platform'),
            'x-delivery-mode' => (string) config('services.lenta.delivery_mode'),
            'x-device-name' => '',
            'x-device-id' => $this->deviceId,
            'deviceid' => $this->deviceId,
            'x-user-session-id' => $this->userSessionId,
            'x-device-brand' => '',
            'sessiontoken' => $this->sessionToken(),
            'accept-language' => 'ru-RU,ru;q=0.9',
            'experiments' => $this->experimentsHeader(),
            'referer' => $referer ?: (string) config('services.lenta.web_url'),
            'sec-ch-ua-platform' => '"Windows"',
            'sec-ch-ua' => '"Chromium";v="148", "Google Chrome";v="148", "Not/A)Brand";v="99"',
            'sec-ch-ua-mobile' => '?0',
            'sec-fetch-site' => 'same-origin',
            'sec-fetch-mode' => 'cors',
            'sec-fetch-dest' => 'empty',
            'priority' => 'u=1, i',
            'user-agent' => $this->browserUserAgent(),
            'accept-encoding' => 'gzip, deflate, br, zstd',
            'cookie' => $this->cookieHeader(),
        ];

        if ($includeOrigin) {
            $headers['origin'] = rtrim((string) config('services.lenta.web_url'), '/');
        }

        if ($regionSlug !== null && $regionSlug !== '') {
            $headers['x-domain'] = $regionSlug;
        } else {
            $headers['x-domain'] = (string) config('services.lenta.default_domain');
        }

        return $headers + $this->traceHeaders();
    }

    /** @return array<string, string> */
    private function jsonApiHeaders(?string $regionSlug = null, bool $includeOrigin = false, ?string $referer = null): array
    {
        return $this->apiHeaders($regionSlug, $includeOrigin, $referer) + [
            'content-type' => 'application/json',
        ];
    }

    private function experimentsHeader(): string
    {
        $configured = config('services.lenta.experiments_header');
        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $cookieExperiments = config('services.lenta.growthbook_cookie_experiments');
        if (is_string($cookieExperiments) && trim($cookieExperiments) !== '') {
            return trim(urldecode($cookieExperiments));
        }

        return '';
    }

    /** @return array<string, string> */
    private function catalogItemsHeaders(string $regionSlug, int $categoryId, ?string $categorySlug): array
    {
        return $this->jsonApiHeaders(
            $regionSlug,
            true,
            $this->catalogCategoryReferer($categoryId, $categorySlug),
        );
    }

    private function catalogCategoryReferer(int $categoryId, ?string $categorySlug): string
    {
        $base = rtrim((string) config('services.lenta.web_url'), '/');
        if (!is_string($categorySlug) || trim($categorySlug) === '') {
            return $base.'/catalog/';
        }

        return sprintf('%s/catalog/%s-%d/', $base, trim($categorySlug), $categoryId);
    }

    /** @param array<string, mixed> $storeMeta */
    private function applyPickupCookies(string $regionSlug, int $storeId, array $storeMeta): void
    {
        $host = parse_url((string) config('services.lenta.web_url'), PHP_URL_HOST) ?: 'lenta.com';

        $missionPayload = [
            't' => 'pickup',
            'ids' => false,
            'ma' => [
                'i' => $storeId,
                'a' => (string) ($storeMeta['alias'] ?? $storeMeta['external_id'] ?? $storeId),
                't' => (string) ($storeMeta['title'] ?? $storeMeta['name'] ?? ('Store '.$storeId)),
                'af' => (string) ($storeMeta['address'] ?? ''),
                'ri' => isset($storeMeta['region_id']) && is_numeric($storeMeta['region_id']) ? (int) $storeMeta['region_id'] : null,
                'mt' => (string) ($storeMeta['market_type'] ?? $storeMeta['type'] ?? ''),
                's' => false,
            ],
        ];

        foreach ([
            'App_Cache_CitySlug' => $regionSlug,
            'App_Cache_MissionAddressMode' => json_encode($missionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'CatalogSelectedSorting' => 'sale',
        ] as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $this->cookieJar->setCookie(new SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => $host,
                'Path' => '/',
                'Discard' => false,
            ]));
        }
    }

    /** @return array<string, string> */
    private function traceHeaders(): array
    {
        $traceId = Str::lower(bin2hex(random_bytes(16)));
        $spanId = Str::lower(bin2hex(random_bytes(8)));

        return [
            'x-trace-id' => $traceId,
            'x-span-id' => $spanId,
            'traceparent' => sprintf('00-%s-%s-01', $traceId, $spanId),
        ];
    }

    private function sessionRequestPayload(): string
    {
        $request = json_encode([
            'Head' => [
                'MarketingPartnerKey' => (string) config('services.lenta.marketing_partner_key'),
                'Version' => (string) config('services.lenta.version'),
                'Client' => (string) config('services.lenta.client'),
                'Method' => 'sessionGet',
                'SessionToken' => '',
                'RequestId' => 'sessionGet_'.Str::lower(Str::random(13)),
                'DeviceId' => $this->deviceId,
                'Domain' => (string) config('services.lenta.default_domain'),
            ],
            'Body' => new \stdClass(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"Head":{},"Body":{}}';

        return 'request='.$request;
    }

    /** @return array<string, mixed>|null */
    private function decodedSessionPayload(string $payload): ?array
    {
        $encoded = str_starts_with($payload, 'request=') ? substr($payload, 8) : $payload;
        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function diagnosticSuffix(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/u', ' ', $body) ?? $body;
        $snippet = mb_substr($normalized, 0, 180);

        return ': '.$snippet;
    }

    private function hasManualAntiBotCookies(): bool
    {
        if (
            is_string(config('services.lenta.qrator_ssid'))
            && trim((string) config('services.lenta.qrator_ssid')) !== ''
            && is_string(config('services.lenta.utk_marketing_group_token'))
            && trim((string) config('services.lenta.utk_marketing_group_token')) !== ''
            && is_string(config('services.lenta.utk_sss_token'))
            && trim((string) config('services.lenta.utk_sss_token')) !== ''
        ) {
            return true;
        }

        return is_string(config('services.lenta.qrator_jsr'))
            && trim((string) config('services.lenta.qrator_jsr')) !== ''
            && is_string(config('services.lenta.qrator_jsid'))
            && trim((string) config('services.lenta.qrator_jsid')) !== '';
    }

    private function hasManualSessionConfiguration(): bool
    {
        return is_string(config('services.lenta.device_id'))
            && trim((string) config('services.lenta.device_id')) !== ''
            && is_string(config('services.lenta.user_session_id'))
            && trim((string) config('services.lenta.user_session_id')) !== ''
            && (
                $this->hasManualAntiBotCookies()
                || $this->configuredSessionToken() !== null
                || $this->rawCookieHeader() !== null
            );
    }

    private function ensureManualSessionConfiguration(): void
    {
        if ($this->hasManualSessionConfiguration()) {
            return;
        }

        throw new RuntimeException(
            'Lenta manual session is not configured. Set LENTA_DEVICE_ID, LENTA_USER_SESSION_ID and either LENTA_SESSION_TOKEN, LENTA_RAW_COOKIE_HEADER, or anti-bot cookies such as LENTA_QRATOR_SSID + LENTA_UTK_MARKETING_GROUP_TOKEN + LENTA_UTK_SSS_TOKEN / LENTA_QRATOR_JSR + LENTA_QRATOR_JSID in backend/.env.'
        );
    }

    private function configuredSessionToken(): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        $configured = config('services.lenta.session_token');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }

    private function rawCookieHeader(): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        $configured = config('services.lenta.raw_cookie_header');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, headers:array<int, string>}
     */
    private function sendCurlRequest(string $method, string $url, array $headers, ?string $body = null): array
    {
        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name.': '.$value;
        }

        $responseHeaders = [];
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_TIMEOUT => (int) config('services.lenta.timeout', 30),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $trimmed = trim($headerLine);
                if ($trimmed !== '') {
                    $responseHeaders[] = $trimmed;
                }

                return strlen($headerLine);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new RuntimeException('Lenta cURL request failed: '.$error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    /** @param array<int, string> $headers */
    private function storeResponseCookies(array $headers): void
    {
        $host = parse_url((string) config('services.lenta.web_url'), PHP_URL_HOST) ?: 'lenta.com';

        foreach ($headers as $headerLine) {
            if (! str_starts_with(strtolower($headerLine), 'set-cookie:')) {
                continue;
            }

            $cookie = SetCookie::fromString(trim(substr($headerLine, strlen('set-cookie:'))));
            if (! $cookie->getDomain()) {
                $cookie->setDomain($host);
            }

            if (! $cookie->getPath()) {
                $cookie->setPath('/');
            }

            $this->cookieJar->setCookie($cookie);
        }
    }

    /** @param array<string, mixed> $context */
    private function logStep(string $step, array $context = []): void
    {
        Log::info('lenta_api.'.$step, $context + [
            'device_id' => $this->deviceId,
            'user_session_id' => $this->userSessionId,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function throwRequestException(string $stage, int $status, string $body = '', array $context = []): never
    {
        $message = sprintf(
            'Lenta %s returned HTTP %d%s',
            $stage,
            $status,
            $this->diagnosticSuffix($body),
        );

        Log::error('lenta_api.failure', $context + [
            'stage' => $stage,
            'status' => $status,
            'body_snippet' => mb_substr(trim(preg_replace('/\s+/u', ' ', $body) ?? $body), 0, 500),
            'device_id' => $this->deviceId,
            'user_session_id' => $this->userSessionId,
        ]);

        throw new RuntimeException($message);
    }
}
