<?php

namespace Tests\Unit;

use App\Services\CityResolver;
use PHPUnit\Framework\TestCase;

class CityResolverTest extends TestCase
{
    public function test_it_extracts_city_from_russian_address(): void
    {
        $resolver = new CityResolver();

        $this->assertSame('Красноярск', $resolver->extractFromAddress('Россия, г. Красноярск, ул. Мира, 10'));
        $this->assertSame('Москва', $resolver->extractFromAddress('Москва, улица Ленина, 5'));
    }

    public function test_it_extracts_city_before_district_without_city_prefix(): void
    {
        $resolver = new CityResolver();

        $this->assertSame(
            'Новокузнецк',
            $resolver->extractFromAddress('Кемеровская область - Кузбасс, Новокузнецк, р-н Центральный, ул Циолковского, 34, пом. 131'),
        );
    }
}
