<?php

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Services\Sales\SaleCalculator;
use PHPUnit\Framework\TestCase;

class SaleCalculatorTest extends TestCase
{
    public function test_totals_without_tax_or_discount(): void
    {
        $r = SaleCalculator::calculate([
            ['price' => '1000', 'quantity' => '3'],
            ['price' => '250', 'quantity' => '2'],
        ]);

        $this->assertSame('3500.00', $r['subtotal']);
        $this->assertSame('3500.00', $r['total']);
    }

    public function test_line_and_invoice_discount_are_allocated_exactly(): void
    {
        $r = SaleCalculator::calculate([
            ['price' => '100', 'quantity' => '1'],
            ['price' => '100', 'quantity' => '1'],
            ['price' => '100', 'quantity' => '1', 'discount' => '10'],
        ], '10');

        $this->assertSame('300.00', $r['subtotal']);
        $this->assertSame('20.00', $r['discount']);
        $this->assertSame('280.00', $r['total']);
        $sum = array_sum(array_map(fn ($l) => (float) $l['discount'], $r['lines']));
        $this->assertEquals(20.0, $sum);
    }

    public function test_exclusive_tax(): void
    {
        $r = SaleCalculator::calculate([['price' => '1000', 'quantity' => '2', 'tax_rate' => '15']]);

        $this->assertSame('300.00', $r['tax']);
        $this->assertSame('2300.00', $r['total']);
    }

    public function test_inclusive_tax_is_extracted_from_price(): void
    {
        $r = SaleCalculator::calculate([['price' => '1150', 'quantity' => '1', 'tax_rate' => '15']], '0', true);

        $this->assertSame('150.00', $r['tax']);
        $this->assertSame('1150.00', $r['total']);
    }

    public function test_tax_after_discount(): void
    {
        $r = SaleCalculator::calculate([['price' => '1000', 'quantity' => '1', 'tax_rate' => '10']], '100');

        $this->assertSame('90.00', $r['tax']);
        $this->assertSame('990.00', $r['total']);
    }

    public function test_discount_larger_than_amount_is_rejected(): void
    {
        $this->expectException(BusinessException::class);
        SaleCalculator::calculate([['price' => '100', 'quantity' => '1']], '101');
    }
}
