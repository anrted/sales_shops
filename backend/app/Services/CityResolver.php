<?php

namespace App\Services;

use App\Models\City;
use Illuminate\Support\Str;

class CityResolver
{
    public function resolve(?string $cityName = null, ?string $address = null): ?City
    {
        $name = $this->normalize($cityName) ?? $this->extractFromAddress($address);

        if (!$name) {
            return null;
        }

        return City::query()->firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        );
    }

    public function extractFromAddress(?string $address): ?string
    {
        if (!$address) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $address) ?? $address);

        $patterns = [
            '/(?:^|,\s*)(?:г\.?|город)\s*([А-ЯЁA-Z][А-ЯЁа-яёA-Za-z\-\s]+?)(?=,|$)/u',
            '/(?:^|,\s*)(?![^,]*(?:область|край|республика|округ|автономный|АО)\b)([А-ЯЁA-Z][А-ЯЁа-яёA-Za-z\-\s]+?)\s*,\s*(?=р-н|район|ул\.?|улица|пр-кт|проспект|шоссе|пер\.?|переулок|б-р|бульвар|пл\.?|площадь|мкр|микрорайон|наб\.?)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                return $this->normalize($matches[1] ?? null);
            }
        }

        return null;
    }

    public function normalize(?string $name): ?string
    {
        if (!$name) {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        $name = preg_replace('/^(г\.?|город)\s+/iu', '', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B,.");

        return $name !== '' ? Str::title(Str::lower($name)) : null;
    }
}
