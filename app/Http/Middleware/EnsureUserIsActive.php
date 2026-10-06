<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks disabled accounts even if they still hold a valid token or session. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->is_active || $user->trashed())) {
            $user->currentAccessToken()?->delete();
            abort(403, __('auth.inactive'));
        }

        return $next($request);
    }
}
