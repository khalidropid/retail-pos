<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Support\Money;

/**
 * Pure invoice math (no database access).
 *
 * - Line discount is an amount on the line.
 * - The invoice-level discount is spread over lines in proportion to their
 *   net amount; the last line absorbs rounding so the parts add up exactly.
 *   Storing the allocated share per line lets returns refund the right value.
 * - Tax is per line, either added on top (exclusive) or extracted from the
 *   price (inclusive).
 */
final class SaleCalculator
{
    /**
     * @param  array<int, array{price:string, quantity:string, discount?:string, tax_rate?:string}>  $lines
     * @return array{lines: array<int, array{gross:string, discount:string, net:string, tax:string, total:string}>, subtotal:string, discount:string, tax:string, total:string}
     */
    public static function calculate(array $lines, string $invoiceDiscount = '0', bool $pricesIncludeTax = false, bool $taxEnabled = true): array
    {
        $invoiceDiscount = Money::round($invoiceDiscount);
        if (Money::isNegative($invoiceDiscount)) {
            throw new BusinessException('الخصم لا يمكن أن يكون سالبًا.');
        }

        $prepared = [];
        $netSum = '0';
        foreach ($lines as $i => $line) {
            $gross = Money::mul($line['price'], $line['quantity']);
            $lineDiscount = Money::round($line['discount'] ?? '0');

            if (Money::isNegative($lineDiscount)) {
                throw new BusinessException('الخصم لا يمكن أن يكون سالبًا.');
            }
            if (Money::gt($lineDiscount, $gross)) {
                throw new BusinessException('خصم السطر أكبر من قيمته.');
            }

            $net = Money::sub($gross, $lineDiscount);
            $prepared[$i] = ['gross' => $gross, 'line_discount' => $lineDiscount, 'net' => $net, 'tax_rate' => $line['tax_rate'] ?? '0'];
            $netSum = Money::add($netSum, $net);
        }

        if (Money::gt($invoiceDiscount, $netSum)) {
            throw new BusinessException('خصم الفاتورة أكبر من قيمة الأصناف.');
        }

        $result = [];
        $allocated = '0';
        $keys = array_keys($prepared);
        $lastKey = end($keys);
        $totals = ['subtotal' => '0', 'discount' => '0', 'tax' => '0', 'total' => '0'];

        foreach ($prepared as $i => $p) {
            if ($i === $lastKey) {
                $share = Money::sub($invoiceDiscount, $allocated);
            } else {
                $share = Money::isZero($netSum) ? '0' : Money::div(Money::of($invoiceDiscount)->multipliedBy(Money::of($p['net'])), $netSum, 2);
                $allocated = Money::add($allocated, $share);
            }

            $discount = Money::add($p['line_discount'], $share);
            $net = Money::sub($p['gross'], $discount);
            $rate = $taxEnabled ? Money::round($p['tax_rate']) : '0';

            if (Money::isZero($rate)) {
                $tax = '0.00';
                $total = $net;
            } elseif ($pricesIncludeTax) {
                $exTax = Money::div($net, Money::add('1', Money::div($rate, 100, 6)), 2);
                $tax = Money::sub($net, $exTax);
                $total = $net;
            } else {
                $tax = Money::mul($net, Money::div($rate, 100, 6));
                $total = Money::add($net, $tax);
            }

            $result[$i] = ['gross' => $p['gross'], 'discount' => $discount, 'net' => $net, 'tax' => $tax, 'total' => $total];
            $totals['subtotal'] = Money::add($totals['subtotal'], $p['gross']);
            $totals['discount'] = Money::add($totals['discount'], $discount);
            $totals['tax'] = Money::add($totals['tax'], $tax);
            $totals['total'] = Money::add($totals['total'], $total);
        }

        return ['lines' => $result] + $totals;
    }
}
