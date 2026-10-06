<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Throwable;

/** PostgreSQL deadlock / serialization failure: safe to retry. */
final class DbConflict
{
    public const MESSAGE = 'تعارضت العملية مع عملية أخرى تتم في نفس اللحظة. لم يُحفظ شيء، يرجى إعادة المحاولة.';

    public static function is(Throwable $e): bool
    {
        return $e instanceof QueryException && in_array((string) $e->getCode(), ['40P01', '40001'], true);
    }
}
