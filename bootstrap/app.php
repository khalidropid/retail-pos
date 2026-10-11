<?php

use App\Http\Middleware\BlockWritesDuringRestore;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use App\Support\DbConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
        $middleware->append(SecurityHeaders::class);
        $middleware->append(BlockWritesDuringRestore::class);
        $middleware->alias(['active' => EnsureUserIsActive::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && DbConflict::is($e)) {
                return response()->json(['message' => DbConflict::MESSAGE], 409);
            }
        });
        // Domain rule violations and authorization failures carry Arabic messages.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage() ?: 'غير مصرح لك بتنفيذ هذه العملية.'], 403);
            }
        });
    })->create();

/*
| Desktop (Windows) installation: the code lives read-only under Program
| Files, while .env, storage (logs, sessions, cache, uploads, backups) and
| the bootstrap caches live in the data directory the launcher/installer
| passes in RETAILPOS_DATA_DIR. Without it nothing changes.
*/
$dataDir = $_SERVER['RETAILPOS_DATA_DIR'] ?? $_ENV['RETAILPOS_DATA_DIR'] ?? getenv('RETAILPOS_DATA_DIR') ?: null;
if ($dataDir) {
    $dataDir = rtrim($dataDir, '/\\');
    $app->useEnvironmentPath($dataDir);

    // Laravel only treats paths starting with / or \ as absolute; on Windows the
    // data dir starts with a drive letter (C:\...), so register that prefix too.
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $dataDir)) {
        $app->addAbsoluteCachePathPrefix(substr($dataDir, 0, 3));
    }
    $app->useStoragePath($dataDir.DIRECTORY_SEPARATOR.'storage');

    // Self-heal the writable skeleton (first launch, or a folder removed by hand).
    foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $dir) {
        $full = $dataDir.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $dir);
        if (! is_dir($full)) {
            @mkdir($full, 0775, true);
        }
    }
    if (! is_dir($dataDir.DIRECTORY_SEPARATOR.'cache')) {
        @mkdir($dataDir.DIRECTORY_SEPARATOR.'cache', 0775, true);
    }

    foreach ([
        'APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php',
        'APP_CONFIG_CACHE' => 'config.php', 'APP_ROUTES_CACHE' => 'routes-v7.php', 'APP_EVENTS_CACHE' => 'events.php',
    ] as $key => $file) {
        if (getenv($key) === false && ! isset($_SERVER[$key])) {
            $path = $dataDir.DIRECTORY_SEPARATOR.'cache'.DIRECTORY_SEPARATOR.$file;
            putenv("{$key}={$path}");
            $_SERVER[$key] = $_ENV[$key] = $path;
        }
    }
}

return $app;
