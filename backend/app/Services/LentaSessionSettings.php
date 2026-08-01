<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

class LentaSessionSettings
{
    private const STATUS_FILE = 'app/lenta-session-refresh.json';

    public function __construct(
        private readonly EnvFileEditor $envFileEditor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $status = $this->readStatus();
        $settings = [
            'default_domain' => (string) config('services.lenta.default_domain', ''),
            'web_url' => (string) config('services.lenta.web_url', ''),
            'device_id' => (string) config('services.lenta.device_id', ''),
            'user_session_id' => (string) config('services.lenta.user_session_id', ''),
            'session_token' => (string) config('services.lenta.session_token', ''),
            'raw_cookie_header' => (string) config('services.lenta.raw_cookie_header', ''),
            'qrator_jsr' => (string) config('services.lenta.qrator_jsr', ''),
            'qrator_jsid' => (string) config('services.lenta.qrator_jsid', ''),
            'qrator_ssid' => (string) config('services.lenta.qrator_ssid', ''),
            'utk_marketing_group_token' => (string) config('services.lenta.utk_marketing_group_token', ''),
            'utk_sss_token' => (string) config('services.lenta.utk_sss_token', ''),
            'growthbook_user_id' => (string) config('services.lenta.growthbook_user_id', ''),
            'growthbook_experiments' => (string) config('services.lenta.growthbook_experiments', ''),
            'growthbook_cookie_experiments' => (string) config('services.lenta.growthbook_cookie_experiments', ''),
            'app_cache_city' => (string) config('services.lenta.app_cache_city', ''),
            'iap_uid' => (string) config('services.lenta.iap_uid', ''),
            'browser_user_agent' => (string) config('services.lenta.browser_user_agent', ''),
        ];

        return [
            'settings' => $settings,
            'is_configured' => $this->isConfigured($settings),
            'cookie_count' => $this->cookieCount($settings['raw_cookie_header']),
            'raw_cookie_preview' => $this->previewCookieHeader($settings['raw_cookie_header']),
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input, string $source = 'manual'): array
    {
        $normalized = $this->normalize($input);
        $this->envFileEditor->update($this->toEnvMap($normalized));

        $this->writeStatus([
            'source' => $source,
            'updated_at' => Carbon::now()->toIso8601String(),
            'cookie_count' => $this->cookieCount((string) ($normalized['raw_cookie_header'] ?? '')),
            'default_domain' => (string) ($normalized['default_domain'] ?? ''),
            'message' => $source === 'manual'
                ? 'Lenta session settings were updated from admin panel.'
                : 'Lenta session settings were refreshed automatically.',
        ]);

        return [
            'settings' => $normalized,
            'status' => $this->readStatus(),
            'is_configured' => $this->isConfigured($normalized),
            'cookie_count' => $this->cookieCount((string) ($normalized['raw_cookie_header'] ?? '')),
            'raw_cookie_preview' => $this->previewCookieHeader((string) ($normalized['raw_cookie_header'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    public function saveCaptured(array $capture): array
    {
        $cookies = is_array($capture['cookies'] ?? null) ? $capture['cookies'] : [];
        $cookieMap = [];
        foreach ($cookies as $cookie) {
            if (!is_array($cookie)) {
                continue;
            }

            $name = $cookie['name'] ?? null;
            $value = $cookie['value'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $cookieMap[$name] = is_scalar($value) || $value === null ? (string) $value : '';
        }

        $rawCookieHeader = $this->buildRawCookieHeader($cookies);
        if ($rawCookieHeader === '') {
            throw new RuntimeException('Browser session refresh did not produce any lenta.com cookies.');
        }

        $payload = [
            'default_domain' => $cookieMap['App_Cache_CitySlug'] ?? config('services.lenta.default_domain'),
            'device_id' => $cookieMap['Utk_DvcGuid'] ?? ((string) config('services.lenta.device_id') !== '' ? config('services.lenta.device_id') : (string) Str::uuid()),
            'user_session_id' => $cookieMap['UserSessionId'] ?? ((string) config('services.lenta.user_session_id') !== '' ? config('services.lenta.user_session_id') : (string) Str::uuid()),
            'session_token' => $cookieMap['Utk_SessionToken'] ?? config('services.lenta.session_token'),
            'raw_cookie_header' => $rawCookieHeader,
            'qrator_jsr' => $cookieMap['qrator_jsr'] ?? config('services.lenta.qrator_jsr'),
            'qrator_jsid' => $cookieMap['qrator_jsid'] ?? config('services.lenta.qrator_jsid'),
            'qrator_ssid' => $cookieMap['qrator_ssid'] ?? config('services.lenta.qrator_ssid'),
            'utk_marketing_group_token' => $cookieMap['Utk_MrkGrpTkn'] ?? config('services.lenta.utk_marketing_group_token'),
            'utk_sss_token' => $cookieMap['Utk_SssTkn'] ?? config('services.lenta.utk_sss_token'),
            'growthbook_user_id' => $cookieMap['GrowthBook_user_id'] ?? config('services.lenta.growthbook_user_id'),
            'growthbook_experiments' => $cookieMap['GrowthBook_experiments'] ?? config('services.lenta.growthbook_experiments'),
            'growthbook_cookie_experiments' => $cookieMap['GrowthBook_Cookie_Experiments'] ?? config('services.lenta.growthbook_cookie_experiments'),
            'app_cache_city' => $cookieMap['App_Cache_City'] ?? config('services.lenta.app_cache_city'),
            'iap_uid' => $cookieMap['iap.uid'] ?? config('services.lenta.iap_uid'),
            'browser_user_agent' => is_string($capture['userAgent'] ?? null) ? $capture['userAgent'] : config('services.lenta.browser_user_agent'),
        ];

        return $this->save($payload, 'browser_refresh');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function normalize(array $input): array
    {
        $cookieHeader = $this->sanitizeString($input['raw_cookie_header'] ?? null);
        $cookieValues = $cookieHeader !== '' ? $this->parseCookieHeader($cookieHeader) : [];

        return [
            'default_domain' => $this->sanitizeString($input['default_domain'] ?? ($cookieValues['App_Cache_CitySlug'] ?? config('services.lenta.default_domain'))),
            'web_url' => (string) config('services.lenta.web_url', ''),
            'device_id' => $this->sanitizeString($input['device_id'] ?? ($cookieValues['Utk_DvcGuid'] ?? config('services.lenta.device_id'))),
            'user_session_id' => $this->sanitizeString($input['user_session_id'] ?? ($cookieValues['UserSessionId'] ?? config('services.lenta.user_session_id'))),
            'session_token' => $this->sanitizeString($input['session_token'] ?? ($cookieValues['Utk_SessionToken'] ?? config('services.lenta.session_token'))),
            'raw_cookie_header' => $cookieHeader,
            'qrator_jsr' => $this->sanitizeString($input['qrator_jsr'] ?? ($cookieValues['qrator_jsr'] ?? config('services.lenta.qrator_jsr'))),
            'qrator_jsid' => $this->sanitizeString($input['qrator_jsid'] ?? ($cookieValues['qrator_jsid'] ?? config('services.lenta.qrator_jsid'))),
            'qrator_ssid' => $this->sanitizeString($input['qrator_ssid'] ?? ($cookieValues['qrator_ssid'] ?? config('services.lenta.qrator_ssid'))),
            'utk_marketing_group_token' => $this->sanitizeString($input['utk_marketing_group_token'] ?? ($cookieValues['Utk_MrkGrpTkn'] ?? config('services.lenta.utk_marketing_group_token'))),
            'utk_sss_token' => $this->sanitizeString($input['utk_sss_token'] ?? ($cookieValues['Utk_SssTkn'] ?? config('services.lenta.utk_sss_token'))),
            'growthbook_user_id' => $this->sanitizeString($input['growthbook_user_id'] ?? ($cookieValues['GrowthBook_user_id'] ?? config('services.lenta.growthbook_user_id'))),
            'growthbook_experiments' => $this->sanitizeString($input['growthbook_experiments'] ?? ($cookieValues['GrowthBook_experiments'] ?? config('services.lenta.growthbook_experiments'))),
            'growthbook_cookie_experiments' => $this->sanitizeString($input['growthbook_cookie_experiments'] ?? ($cookieValues['GrowthBook_Cookie_Experiments'] ?? config('services.lenta.growthbook_cookie_experiments'))),
            'app_cache_city' => $this->sanitizeString($input['app_cache_city'] ?? ($cookieValues['App_Cache_City'] ?? config('services.lenta.app_cache_city'))),
            'iap_uid' => $this->sanitizeString($input['iap_uid'] ?? ($cookieValues['iap.uid'] ?? config('services.lenta.iap_uid'))),
            'browser_user_agent' => $this->sanitizeString($input['browser_user_agent'] ?? config('services.lenta.browser_user_agent')),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $cookies
     */
    private function buildRawCookieHeader(array $cookies): string
    {
        $pairs = [];

        foreach ($cookies as $cookie) {
            $name = $cookie['name'] ?? null;
            $value = $cookie['value'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $pairs[] = $name.'='.(is_scalar($value) || $value === null ? (string) $value : '');
        }

        return implode('; ', $pairs);
    }

    /**
     * @return array<string, string|null>
     */
    private function toEnvMap(array $settings): array
    {
        return [
            'LENTA_DEFAULT_DOMAIN' => $settings['default_domain'],
            'LENTA_DEVICE_ID' => $settings['device_id'],
            'LENTA_USER_SESSION_ID' => $settings['user_session_id'],
            'LENTA_SESSION_TOKEN' => $settings['session_token'],
            'LENTA_RAW_COOKIE_HEADER' => $settings['raw_cookie_header'],
            'LENTA_QRATOR_JSR' => $settings['qrator_jsr'],
            'LENTA_QRATOR_JSID' => $settings['qrator_jsid'],
            'LENTA_QRATOR_SSID' => $settings['qrator_ssid'],
            'LENTA_UTK_MARKETING_GROUP_TOKEN' => $settings['utk_marketing_group_token'],
            'LENTA_UTK_SSS_TOKEN' => $settings['utk_sss_token'],
            'LENTA_GROWTHBOOK_USER_ID' => $settings['growthbook_user_id'],
            'LENTA_GROWTHBOOK_EXPERIMENTS' => $settings['growthbook_experiments'],
            'LENTA_GROWTHBOOK_COOKIE_EXPERIMENTS' => $settings['growthbook_cookie_experiments'],
            'LENTA_APP_CACHE_CITY' => $settings['app_cache_city'],
            'LENTA_IAP_UID' => $settings['iap_uid'],
            'LENTA_BROWSER_USER_AGENT' => $settings['browser_user_agent'],
        ];
    }

    private function isConfigured(array $settings): bool
    {
        return $settings['device_id'] !== ''
            && $settings['user_session_id'] !== ''
            && (
                $settings['raw_cookie_header'] !== ''
                || ($settings['qrator_jsr'] !== '' && $settings['qrator_jsid'] !== '')
            );
    }

    /**
     * @return array<string, string>
     */
    private function parseCookieHeader(string $cookieHeader): array
    {
        $parsed = [];

        foreach (explode(';', $cookieHeader) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || !str_contains($chunk, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $chunk, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $parsed[$name] = trim($value);
        }

        return $parsed;
    }

    private function sanitizeString(mixed $value): string
    {
        return is_scalar($value) || $value === null ? trim((string) $value) : '';
    }

    private function cookieCount(string $cookieHeader): int
    {
        return count($this->parseCookieHeader($cookieHeader));
    }

    private function previewCookieHeader(string $cookieHeader): string
    {
        if ($cookieHeader === '') {
            return '';
        }

        return Str::limit($cookieHeader, 220, '...');
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(): array
    {
        $path = storage_path(self::STATUS_FILE);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function writeStatus(array $status): void
    {
        $path = storage_path(self::STATUS_FILE);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents(
            $path,
            json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }
}
