<?php

namespace App\Http\Middleware;

use App\Services\BackupService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a database restore runs, refuse every write request (POST/PUT/
 * PATCH/DELETE, including Livewire updates) so no sale or payment is
 * written into a database that is being replaced. Reads keep working.
 */
class BlockWritesDuringRestore
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && BackupService::restoreInProgress()) {
            $message = 'تجري الآن استعادة قاعدة البيانات. يرجى الانتظار حتى تنتهي ثم إعادة المحاولة.';

            return $request->expectsJson() || $request->is('livewire/*', 'api/*')
                ? response()->json(['message' => $message], 503)
                : response($message, 503);
        }

        return $next($request);
    }
}
