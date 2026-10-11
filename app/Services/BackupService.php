<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use ZipArchive;

/**
 * PostgreSQL backup and restore.
 *
 * - Uses pg_dump / pg_restore (custom format) through Laravel's Process
 *   facade with an argument array: no shell, no user-supplied paths.
 * - The database password goes through the PGPASSWORD environment
 *   variable, never the command line or logs.
 * - Files live in config('backup.path'), outside public/ and the app code.
 * - Binary names/paths come from config, so a Windows build only needs
 *   different .env values.
 */
class BackupService
{
    public const RESTORE_FLAG = 'database_restore_in_progress';

    /** Confirmation phrase the user must type before a restore. */
    public const CONFIRMATION = 'استعادة';

    /** Tables that must exist and be readable after a restore. */
    public const ESSENTIAL_TABLES = [
        'users', 'roles', 'permissions', 'settings', 'products', 'categories', 'stock_movements',
        'customers', 'sales', 'sale_items', 'customer_payments', 'payment_allocations', 'sale_returns',
        'suppliers', 'purchases', 'cash_movements', 'expenses', 'audit_logs',
    ];

    private const NAME_PATTERN = '/^retailpos-\d{8}-\d{6}-(manual|scheduled|pre_restore|pre_upgrade|uploaded)(-[a-z0-9]{6})?\.dump$/';

    public function __construct(private AuditLogger $audit) {}

    // ------------------------------------------------------------- backup

    public function create(string $type = 'manual', ?User $user = null): Backup
    {
        if ($user && ! $user->can('backups.manage')) {
            throw new AuthorizationException('ليس لديك صلاحية إنشاء نسخة احتياطية.');
        }

        $lock = Cache::lock('database-backup', config('backup.timeout') + 60);
        if (! $lock->get()) {
            throw new BusinessException('توجد عملية نسخ احتياطي أخرى قيد التنفيذ. حاول بعد قليل.');
        }

        try {
            return $this->dump($type, $user);
        } finally {
            $lock->release();
        }
    }

    private function dump(string $type, ?User $user): Backup
    {
        $dir = $this->directory();
        $filename = 'retailpos-'.now()->format('Ymd-His').'-'.$type.'-'.Str::lower(Str::random(6)).'.dump';
        $path = $dir.DIRECTORY_SEPARATOR.$filename;

        // An install upgraded from a version older than the backups table must
        // still be able to take its pre-upgrade backup: then the record stays in
        // memory and the file is registered by syncFromDisk() after migrating.
        $backup = new Backup([
            'filename' => $filename, 'type' => $type, 'status' => 'running',
            'created_by' => $user?->id, 'started_at' => now(),
        ]);
        $persist = Schema::connection($backup->getConnectionName())->hasTable('backups');
        if ($persist) {
            $backup->save();
        }

        $db = $this->connectionConfig();
        $result = Process::env(['PGPASSWORD' => (string) ($db['password'] ?? '')])
            ->timeout(config('backup.timeout'))
            ->run([
                config('backup.pg_dump'),
                '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'],
                '--format=custom', '--compress=6', '--no-owner', '--no-privileges',
                '--file='.$path,
                $db['database'],
            ]);

        if (! $result->successful() || ! is_file($path) || filesize($path) === 0) {
            @unlink($path);
            $error = $this->cleanError($result->errorOutput() ?: $result->output()) ?: 'pg_dump لم ينتج ملفًا.';
            $backup->fill(['status' => 'failed', 'error' => $error, 'finished_at' => now()]);
            if ($persist) {
                $backup->save();
            }
            Log::error('Database backup failed', ['backup' => $filename, 'error' => $error]);
            $this->audit->log('backup_failed', $backup, null, ['filename' => $filename], $error, userId: $user?->id);

            throw new BusinessException('فشل إنشاء النسخة الاحتياطية: '.$error);
        }

        @chmod($path, 0600); // backups hold all store data: owner-only

        $updates = [
            'status' => 'completed',
            'size' => filesize($path),
            'checksum' => hash_file('sha256', $path),
            'finished_at' => now(),
        ];

        if (settings('backup.include_files', true)) {
            $updates += $this->archiveFiles(Str::beforeLast($filename, '.dump'));
        }

        $backup->fill($updates);
        if ($persist) {
            $backup->save();
        }
        $this->audit->log('backup_created', $backup, null, [
            'filename' => $filename, 'type' => $type, 'size' => $backup->size, 'files_archive' => $backup->files_archive,
        ], userId: $user?->id);

        if ($type === 'scheduled') {
            $this->applyRetention();
        }

        return $backup;
    }

    /**
     * Zip uploaded files (product images, logo) that PostgreSQL does not hold.
     *
     * @return array{files_archive?: string, files_size?: int}
     */
    private function archiveFiles(string $base): array
    {
        $source = config('backup.files_path');
        if (! is_dir($source) || ! class_exists(ZipArchive::class)) {
            return [];
        }

        $archive = $base.'-files.zip';
        $zip = new ZipArchive;
        if ($zip->open($this->directory().DIRECTORY_SEPARATOR.$archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            Log::warning('Could not create files archive', ['archive' => $archive]);

            return [];
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $zip->addFile($file->getPathname(), ltrim(Str::after($file->getPathname(), $source), '/\\'));
            }
        }
        $zip->close();

        $full = $this->directory().DIRECTORY_SEPARATOR.$archive;
        @chmod($full, 0600);

        return is_file($full) ? ['files_archive' => $archive, 'files_size' => filesize($full)] : [];
    }

    /** Delete scheduled / safety backups older than the retention period. */
    public function applyRetention(): int
    {
        $days = max(1, (int) settings('backup.retention_days', 14));
        $old = Backup::query()->whereIn('type', ['scheduled', 'pre_restore'])
            ->where('created_at', '<', now()->subDays($days))->get();

        foreach ($old as $backup) {
            $this->removeFiles($backup);
            $backup->delete();
        }

        return $old->count();
    }

    public function delete(Backup $backup, User $user): void
    {
        if (! $user->can('backups.manage')) {
            throw new AuthorizationException('ليس لديك صلاحية حذف النسخ الاحتياطية.');
        }
        if ($backup->status === 'running') {
            throw new BusinessException('لا يمكن حذف نسخة قيد الإنشاء.');
        }

        $this->removeFiles($backup);
        $backup->delete();
        $this->audit->log('backup_deleted', null, ['filename' => $backup->filename, 'created_at' => (string) $backup->created_at], null, userId: $user->id);
    }

    private function removeFiles(Backup $backup): void
    {
        foreach (array_filter([$backup->filename, $backup->files_archive]) as $name) {
            $path = $this->safePath($name, mustExist: false);
            if ($path && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Absolute path of a backup file, guaranteed to be inside the backup
     * directory (rejects traversal, separators and unknown names).
     */
    public function safePath(string $filename, bool $mustExist = true): ?string
    {
        if ($filename !== basename($filename) || ! preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
            return null;
        }

        $dir = realpath($this->directory());
        $path = $dir.DIRECTORY_SEPARATOR.$filename;
        if ($mustExist) {
            $real = realpath($path);
            if ($real === false || dirname($real) !== $dir || ! is_file($real)) {
                return null;
            }

            return $real;
        }

        return $path;
    }

    public function directory(): string
    {
        $dir = rtrim((string) config('backup.path'), '/\\');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir;
    }

    // -------------------------------------------------------- validation

    /** A valid custom-format dump starts with "PGDMP" and pg_restore can list it. */
    public function validateDump(string $path): void
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle ? fread($handle, 5) : '';
        if ($handle) {
            fclose($handle);
        }

        if ($magic !== 'PGDMP') {
            throw new BusinessException('الملف ليس نسخة احتياطية صالحة من PostgreSQL (صيغة custom).');
        }

        // The table of contents goes to a file rather than captured stdout, which is
        // more dependable for console tools launched from PHP on Windows.
        $listFile = tempnam(sys_get_temp_dir(), 'pglist');
        try {
            $list = Process::timeout(120)->run([config('backup.pg_restore'), '--list', '--file='.$listFile, $path]);
            $contents = (string) @file_get_contents($listFile) ?: $list->output();
        } finally {
            @unlink($listFile);
        }
        if (! $list->successful()) {
            throw new BusinessException('الملف تالف أو غير متوافق: '.$this->cleanError($list->errorOutput()));
        }
        if (! str_contains($contents, 'TABLE') || ! str_contains($contents, 'products')) {
            throw new BusinessException('الملف لا يبدو نسخة احتياطية لهذا النظام (لا يحتوي على جداول المتجر).');
        }
    }

    public function importUpload(UploadedFile $file, User $user): Backup
    {
        if (! $user->can('backups.restore')) {
            throw new AuthorizationException('ليس لديك صلاحية رفع نسخة للاستعادة.');
        }
        if (! in_array(strtolower($file->getClientOriginalExtension()), ['dump', 'backup'], true)) {
            throw new BusinessException('يجب أن يكون الملف بامتداد .dump');
        }
        if ($file->getSize() > config('backup.max_upload_mb') * 1024 * 1024) {
            throw new BusinessException('حجم الملف أكبر من الحد المسموح.');
        }

        // Validate the temp file first; the user's file name is never used.
        $this->validateDump($file->getRealPath());

        $filename = 'retailpos-'.now()->format('Ymd-His').'-uploaded-'.Str::lower(Str::random(6)).'.dump';
        $file->move($this->directory(), $filename);
        $path = $this->safePath($filename);
        @chmod($path, 0600);

        $backup = Backup::query()->create([
            'filename' => $filename, 'type' => 'uploaded', 'status' => 'completed',
            'size' => filesize($path), 'checksum' => hash_file('sha256', $path),
            'created_by' => $user->id, 'started_at' => now(), 'finished_at' => now(),
        ]);

        $this->audit->log('backup_uploaded', $backup, null, ['filename' => $filename, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 150)], userId: $user->id);

        return $backup;
    }

    // ------------------------------------------------------------ restore

    /**
     * Replace the current database with a backup.
     *
     * Steps: authorize + confirm -> validate file -> safety backup of the
     * current data -> block writes -> recreate the public schema and load
     * the dump in ONE transaction (a failure leaves the current data
     * untouched) -> run pending (additive) migrations -> verify essential
     * tables -> audit. Never drops the database or runs migrate:fresh.
     */
    public function restore(Backup $backup, User $user, string $confirmation): array
    {
        if (! $user->can('backups.restore')) {
            throw new AuthorizationException('ليس لديك صلاحية استعادة قاعدة البيانات.');
        }
        if (trim($confirmation) !== self::CONFIRMATION) {
            throw new BusinessException('اكتب كلمة التأكيد "'.self::CONFIRMATION.'" بالضبط لتنفيذ الاستعادة.');
        }
        if (! $backup->isCompleted()) {
            throw new BusinessException('لا يمكن الاستعادة من نسخة غير مكتملة.');
        }

        $path = $this->safePath($backup->filename) ?? throw new BusinessException('ملف النسخة غير موجود في مجلد النسخ الاحتياطية.');
        if ($backup->checksum && hash_file('sha256', $path) !== $backup->checksum) {
            throw new BusinessException('بصمة الملف لا تطابق المسجلة؛ قد يكون الملف معدّلًا أو تالفًا.');
        }
        $this->validateDump($path);

        $lock = Cache::lock('database-backup', config('backup.timeout') * 2 + 60);
        if (! $lock->get()) {
            throw new BusinessException('توجد عملية نسخ أو استعادة أخرى قيد التنفيذ.');
        }

        $source = $backup->filename;
        $userId = $user->id;
        $userEmail = $user->email;

        try {
            // 1) Safety copy of the current data. No safety copy -> no restore.
            $safety = $this->dump('pre_restore', $user);

            // 2) Block writes from other users while the data is replaced.
            Cache::put(self::RESTORE_FLAG, ['by' => $userEmail, 'at' => now()->toIso8601String()], config('backup.timeout') + 60);

            $db = $this->connectionConfig();
            $env = ['PGPASSWORD' => (string) ($db['password'] ?? '')];
            if (config('backup.reconnect', true)) {
                // Release our session so it does not hold locks on the schema being replaced.
                DB::connection(config('backup.connection'))->disconnect();
            }

            // Convert the dump to SQL, then replace the whole public schema in
            // ONE transaction: tables added by newer migrations are dropped too
            // (so migrate can recreate them), and any error rolls everything back.
            $sqlFile = $this->directory().DIRECTORY_SEPARATOR.'restore-'.Str::lower(Str::random(10)).'.sql';
            try {
                $convert = Process::timeout(config('backup.timeout'))->run([
                    config('backup.pg_restore'), '--no-owner', '--no-privileges', '--file='.$sqlFile, $path,
                ]);
                if (! $convert->successful()) {
                    throw new BusinessException('تعذّر تجهيز ملف الاستعادة: '.$this->cleanError($convert->errorOutput()));
                }

                $result = Process::env($env)->timeout(config('backup.timeout'))->run([
                    config('backup.psql'), '--no-psqlrc', '--quiet', '--single-transaction',
                    '--set=ON_ERROR_STOP=1',
                    '--host='.$db['host'], '--port='.$db['port'], '--username='.$db['username'], '--dbname='.$db['database'],
                    '--command=DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;',
                    '--file='.$sqlFile,
                ]);
            } finally {
                @unlink($sqlFile);
            }

            if (! $result->successful()) {
                $error = $this->cleanError($result->errorOutput() ?: $result->output());
                if (config('backup.reconnect', true)) {
                    DB::connection(config('backup.connection'))->reconnect();
                }
                Log::error('Database restore failed; current data kept (single transaction rolled back)', ['backup' => $source, 'error' => $error]);
                $this->audit->log('database_restore_failed', null, null, ['backup' => $source, 'safety_backup' => $safety->filename], $error, userId: $userId);

                throw new BusinessException('فشلت الاستعادة ولم تتغير البيانات الحالية: '.$error);
            }

            // 3) Bring an older backup's schema up to date (additive only).
            Artisan::call('migrate', ['--force' => true, '--database' => config('backup.connection')]);

            // 4) Verify.
            $counts = $this->verifyEssentialTables();

            // The restored DB has its own (older) backups list: re-register files on disk.
            $this->syncFromDisk();

            Cache::forget(self::RESTORE_FLAG);
            app(SettingsService::class)->flush();
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $restoredUser = User::query()->where('email', $userEmail)->value('id');
            $this->audit->log('database_restored', null, null, [
                'backup' => $source, 'safety_backup' => $safety->filename, 'rows' => $counts,
            ], 'استعادة بواسطة '.$userEmail, userId: $restoredUser);
            Log::warning('Database restored from backup', ['backup' => $source, 'by' => $userEmail, 'safety_backup' => $safety->filename]);

            return ['safety_backup' => $safety->filename, 'counts' => $counts];
        } catch (Throwable $e) {
            Log::error('Restore aborted', ['backup' => $source, 'error' => $e->getMessage()]);

            throw $e instanceof BusinessException || $e instanceof AuthorizationException
                ? $e
                : new BusinessException('فشلت الاستعادة: '.$this->cleanError($e->getMessage()));
        } finally {
            Cache::forget(self::RESTORE_FLAG);
            $lock->release();
        }
    }

    /** @return array<string, int> */
    public function verifyEssentialTables(): array
    {
        $connection = DB::connection(config('backup.connection'));
        if (config('backup.reconnect', true)) {
            $connection->reconnect();
        }
        $counts = [];

        foreach (self::ESSENTIAL_TABLES as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                throw new BusinessException("التحقق بعد الاستعادة فشل: الجدول \"{$table}\" غير موجود.");
            }
            $counts[$table] = (int) $connection->table($table)->count();
        }

        if ($counts['users'] === 0) {
            throw new BusinessException('التحقق بعد الاستعادة فشل: لا يوجد مستخدمون في النسخة المستعادة.');
        }

        return $counts;
    }

    /** Register backup files that exist on disk but not in the table. */
    public function syncFromDisk(): int
    {
        $added = 0;
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'retailpos-*.dump') ?: [] as $file) {
            $name = basename($file);
            if (! preg_match(self::NAME_PATTERN, $name, $m) || Backup::query()->where('filename', $name)->exists()) {
                continue;
            }
            $zip = Str::beforeLast($name, '.dump').'-files.zip';
            $zipPath = $this->safePath($zip);
            Backup::query()->create([
                'filename' => $name, 'type' => $m[1], 'status' => 'completed', 'size' => filesize($file),
                'checksum' => hash_file('sha256', $file),
                'files_archive' => $zipPath ? $zip : null, 'files_size' => $zipPath ? filesize($zipPath) : null,
                'started_at' => date('Y-m-d H:i:s', filemtime($file)), 'finished_at' => date('Y-m-d H:i:s', filemtime($file)),
            ]);
            $added++;
        }

        return $added;
    }

    public static function restoreInProgress(): bool
    {
        return Cache::has(self::RESTORE_FLAG);
    }

    // ------------------------------------------------------------ helpers

    /** @return array{host:string, port:int|string, database:string, username:string, password:?string} */
    private function connectionConfig(): array
    {
        $config = DB::connection(config('backup.connection'))->getConfig();
        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new BusinessException('النسخ الاحتياطي مدعوم لقواعد PostgreSQL فقط.');
        }

        return [
            'host' => (string) ($config['host'] ?? '127.0.0.1'),
            'port' => $config['port'] ?? 5432,
            'database' => (string) $config['database'],
            'username' => (string) $config['username'],
            'password' => $config['password'] ?? null,
        ];
    }

    /** Trim tool output and make sure no password ever reaches logs or UI. */
    private function cleanError(?string $output): string
    {
        $output = trim((string) $output);
        $password = (string) (DB::connection(config('backup.connection'))->getConfig()['password'] ?? '');
        if ($password !== '') {
            $output = str_replace($password, '******', $output);
        }

        return mb_substr($output, 0, 1000);
    }
}
