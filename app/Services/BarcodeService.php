<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Picqer\Barcode\BarcodeGeneratorSVG;

/**
 * Internal product barcodes.
 *
 * Format: 13 digits = 2-digit prefix (default "20", the GS1 in-store range,
 * which never collides with manufacturer codes) + 10-digit sequence + check
 * digit. Rendered as Code 128, which every common USB scanner reads.
 * Manufacturer barcodes entered by hand are kept as-is in any valid format.
 */
class BarcodeService
{
    /** Printable characters Code 128 can carry and scanners type back. */
    public const MANUAL_PATTERN = '/^[0-9A-Za-z\-\.\/]{4,64}$/';

    public function __construct(private AuditLogger $audit) {}

    /**
     * Reserve the next free internal barcode value. The sequence row is
     * locked, so concurrent callers always get different numbers; values
     * already taken (e.g. typed by hand) are skipped.
     */
    public function nextValue(): string
    {
        return DB::transaction(function () {
            $prefix = preg_replace('/\D/', '', (string) settings('barcode.prefix', '20')) ?: '20';
            $prefix = substr(str_pad($prefix, 2, '0'), 0, 2);

            DB::table('document_sequences')->insertOrIgnore([
                'key' => 'barcode_internal', 'prefix' => $prefix, 'next_number' => 1, 'padding' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('document_sequences')->where('key', 'barcode_internal')->lockForUpdate()->first();
            $number = (int) $row->next_number;

            do {
                $body = $prefix.str_pad((string) $number, 10, '0', STR_PAD_LEFT);
                $code = $body.self::checkDigit($body);
                $number++;
            } while (Product::withTrashed()->where('barcode', $code)->exists());

            DB::table('document_sequences')->where('key', 'barcode_internal')
                ->update(['next_number' => $number, 'updated_at' => now()]);

            return $code;
        });
    }

    /**
     * Give a product an internal barcode. Never overwrites an existing one
     * unless $regenerate is set and the user may regenerate barcodes.
     */
    public function assign(Product $product, User $user, bool $regenerate = false): Product
    {
        if (! $user->can('products.update') && ! $user->can('products.create')) {
            throw new AuthorizationException('ليس لديك صلاحية تعديل المنتجات.');
        }
        if ($regenerate && ! $user->can('products.regenerate_barcode')) {
            throw new AuthorizationException('ليس لديك صلاحية إعادة توليد الباركود.');
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return DB::transaction(function () use ($product, $user, $regenerate) {
                    $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

                    if (filled($locked->barcode) && ! $regenerate) {
                        throw new BusinessException("المنتج \"{$locked->name}\" لديه باركود بالفعل ({$locked->barcode}).");
                    }

                    $old = $locked->barcode;
                    $locked->forceFill(['barcode' => $this->nextValue(), 'barcode_source' => 'internal'])->save();

                    $this->audit->log('barcode_generated', $locked, ['barcode' => $old], ['barcode' => $locked->barcode],
                        $regenerate ? 'إعادة توليد الباركود' : null, userId: $user->id);

                    return $locked;
                });
            } catch (UniqueConstraintViolationException $e) {
                // Someone saved the same code by hand at the same moment: try the next value.
                if ($attempt === 3) {
                    throw new BusinessException('تعذّر توليد باركود فريد، يرجى المحاولة مرة أخرى.');
                }
            }
        }

        return $product;
    }

    public static function checkDigit(string $digits): int
    {
        $sum = 0;
        foreach (str_split(strrev($digits)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValidInternal(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code) && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /** SVG markup for a barcode (Code 128). */
    public function svg(string $code, float $widthFactor = 1.6, float $height = 40): string
    {
        return (new BarcodeGeneratorSVG)->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, $widthFactor, $height);
    }
}
