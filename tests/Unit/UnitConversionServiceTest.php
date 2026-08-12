<?php

namespace Tests\Unit;

use App\Services\UnitConversionService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UnitConversionServiceTest extends TestCase
{
    private UnitConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new UnitConversionService;
    }

    public function test_it_normalizes_kilograms_and_calculates_cost_per_gram_and_kilogram(): void
    {
        $quantity = $this->service->normalize('5', 'kg', 'g');
        $costPerGram = $this->service->calculateBaseUnitCost('220', $quantity);

        $this->assertSame('5000.000000', $quantity);
        $this->assertSame('0.04400000', $costPerGram);
        $this->assertSame('44.0000', $this->service->costForDisplayUnit($costPerGram, 'kg'));
    }

    public function test_it_normalizes_decimal_kilograms_without_using_float_calculation(): void
    {
        $quantity = $this->service->normalize('1.5', 'kg', 'g');
        $costPerGram = $this->service->calculateBaseUnitCost('52', $quantity);

        $this->assertSame('1500.000000', $quantity);
        $this->assertSame('0.03466667', $costPerGram);
    }

    public function test_it_rejects_weight_to_volume_conversion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalize('1', 'kg', 'ml');
    }

    public function test_it_rejects_unit_to_weight_conversion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->normalize('1', 'un', 'g');
    }
}
