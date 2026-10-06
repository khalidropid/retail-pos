<?php

namespace App\Filament\Support;

use App\Exceptions\BusinessException;
use App\Support\DbConflict;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

/**
 * Runs a domain-service call from the UI and turns rule violations into
 * Arabic notifications instead of error pages.
 */
final class Domain
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws Halt when the operation was rejected
     */
    public static function run(callable $callback, ?string $success = null): mixed
    {
        try {
            $result = $callback();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title('تعذّر إتمام العملية')->body($e->getMessage())->persistent()->send();

            throw new Halt;
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title('غير مصرح')
                ->body($e->getMessage() ?: 'ليس لديك صلاحية لتنفيذ هذه العملية.')->persistent()->send();

            throw new Halt;
        } catch (QueryException $e) {
            if (! DbConflict::is($e)) {
                throw $e;
            }
            Notification::make()->warning()->title('أعد المحاولة')->body(DbConflict::MESSAGE)->persistent()->send();

            throw new Halt;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title('بيانات غير صحيحة')
                ->body(collect($e->errors())->flatten()->implode("\n"))->persistent()->send();

            throw new Halt;
        }

        if ($success !== null) {
            Notification::make()->success()->title($success)->send();
        }

        return $result;
    }
}
