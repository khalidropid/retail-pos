<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hands out sequential document numbers (INV-000001 ...).
 *
 * The sequence row is locked with SELECT ... FOR UPDATE, so concurrent
 * transactions serialize on it; if the caller's transaction rolls back the
 * number is returned to the pool. Document tables also carry unique
 * constraints as a second line of defence.
 */
class NumberingService
{
    public function next(string $key): string
    {
        return DB::transaction(function () use ($key) {
            $prefix = (string) settings("numbering.{$key}", strtoupper($key).'-');
            $padding = (int) settings('numbering.padding', 6);

            DB::table('document_sequences')->insertOrIgnore([
                'key' => $key,
                'prefix' => $prefix,
                'next_number' => 1,
                'padding' => $padding,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')->where('key', $key)->lockForUpdate()->first();

            $number = (int) $row->next_number;

            DB::table('document_sequences')->where('key', $key)->update([
                'next_number' => $number + 1,
                'prefix' => $prefix,
                'padding' => $padding,
                'updated_at' => now(),
            ]);

            return $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
        });
    }
}
