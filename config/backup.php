<?php

/*
| Database backup settings. Everything that depends on the machine (paths,
| binaries) comes from .env so the same code runs on Linux servers and a
| future Windows desktop build.
*/

return [
    // Where backup files live. Keep it OUTSIDE the application folder and
    // outside public/, so app updates never delete backups and nothing is
    // web-accessible. Default: storage/app/private/backups.
    'path' => env('BACKUP_PATH') ?: storage_path('app/private/backups'),

    // PostgreSQL client binaries (use full paths on Windows, e.g.
    // "C:\\Program Files\\PostgreSQL\\17\\bin\\pg_dump.exe").
    'pg_dump' => env('PG_DUMP_PATH', 'pg_dump'),
    'pg_restore' => env('PG_RESTORE_PATH', 'pg_restore'),
    'psql' => env('PSQL_PATH', 'psql'),

    // Database connection to back up / restore.
    'connection' => env('BACKUP_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')),

    // Drop and re-open the app's own DB connection around a restore. Only
    // turned off by the regular (transactional) test suite.
    'reconnect' => true,

    // Seconds before pg_dump / pg_restore are aborted.
    'timeout' => (int) env('BACKUP_TIMEOUT', 1800),

    // Largest backup file accepted for upload, in megabytes.
    'max_upload_mb' => (int) env('BACKUP_MAX_UPLOAD_MB', 2048),

    // Uploaded files (product images, logo) stored outside PostgreSQL.
    'files_path' => storage_path('app/public'),
];
