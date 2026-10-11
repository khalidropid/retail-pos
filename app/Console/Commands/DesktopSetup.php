<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Services\BackupService;
use App\Services\SettingsService;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * First-run setup and upgrade for desktop installations. Safe to run on
 * every install/upgrade:
 *
 *  - empty database      -> versioned migrations + ProductionSeeder
 *  - this app's database -> verified backup FIRST, then pending migrations
 *  - unknown non-empty DB -> refuse (never touches foreign data)
 *
 * Never runs migrate:fresh, db:wipe or any destructive reset.
 *
 * Exit codes: 0 ok, 2 database unreachable, 3 unknown database,
 * 4 backup failed (nothing changed), 5 migration failed (backup kept).
 */
class DesktopSetup extends Command
{
    protected $signature = 'desktop:setup
        {--wait=60 : Seconds to wait for PostgreSQL}
        {--credentials-file= : Where to write the first admin login (fresh installs only)}';

    protected $description = 'Initialize or upgrade the RetailPOS database safely (desktop installer)';

    public function handle(BackupService $backups): int
    {
        if (! $this->waitForDatabase((int) $this->option('wait'))) {
            $this->error('DATABASE_UNREACHABLE: تعذّر الاتصال بقاعدة البيانات.');

            return 2;
        }

        $tables = collect(Schema::getTableListing())->map(fn ($t) => str_contains($t, '.') ? substr($t, strpos($t, '.') + 1) : $t);
        $isOurs = $tables->contains('migrations') && DB::table('migrations')->exists();

        if (! $isOurs && $tables->isNotEmpty()) {
            $this->error('UNKNOWN_DATABASE: قاعدة البيانات تحتوي جداول لا تخص هذا النظام. لم يتم تغيير أي شيء.');

            return 3;
        }

        if (! $isOurs) {
            return $this->freshInstall();
        }

        return $this->upgrade($backups);
    }

    private function freshInstall(): int
    {
        $this->info('FRESH_INSTALL: تهيئة قاعدة بيانات جديدة...');

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            $this->error('MIGRATION_FAILED: '.trim(Artisan::output()));

            return 5;
        }

        Artisan::call('db:seed', ['--class' => ProductionSeeder::class, '--force' => true]);

        if (ProductionSeeder::$createdPassword && ($file = $this->option('credentials-file'))) {
            file_put_contents($file, implode(PHP_EOL, [
                'RetailPOS - بيانات الدخول الأولى',
                'البريد: '.ProductionSeeder::$createdEmail,
                'كلمة المرور المؤقتة: '.ProductionSeeder::$createdPassword,
                'غيّر كلمة المرور بعد أول دخول ثم احذف هذا الملف.',
            ]).PHP_EOL);
            @chmod($file, 0600);
            $this->info('CREDENTIALS_WRITTEN');
        }

        $this->info('OK: تم تجهيز قاعدة البيانات.');

        return 0;
    }

    private function upgrade(BackupService $backups): int
    {
        $pending = $this->pendingMigrations();
        $this->info('EXISTING_DATABASE: البيانات الحالية محفوظة. ترحيلات معلّقة: '.count($pending));

        if ($pending !== []) {
            // A verified backup must exist before anything changes.
            try {
                $backup = $backups->create('pre_upgrade');
                $backups->validateDump($backups->safePath($backup->filename));
                $this->info('BACKUP_OK: '.$backup->filename);
            } catch (Throwable $e) {
                $this->error('BACKUP_FAILED: '.$e->getMessage().' — لم يتم تطبيق أي تحديث على قاعدة البيانات.');

                return 4;
            }

            if (Artisan::call('migrate', ['--force' => true]) !== 0) {
                $this->error('MIGRATION_FAILED: '.trim(Artisan::output()).' — النسخة الاحتياطية: '.$backup->filename);

                return 5;
            }
        }

        $this->ensureReferenceData();
        $backups->syncFromDisk(); // registers a pre-upgrade backup taken before the backups table existed
        $this->info('OK: قاعدة البيانات محدّثة.');

        return 0;
    }

    /** Add new settings keys, payment methods/account types and permissions without touching existing ones. */
    private function ensureReferenceData(): void
    {
        app(SettingsService::class)->seedDefaults();
        Artisan::call('db:seed', ['--class' => PaymentMethodSeeder::class, '--force' => true]);

        $names = collect(config('pos_permissions.groups'))->flatMap(fn ($p) => array_keys($p));
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // Only the full-access admin role receives new permissions automatically;
        // other roles keep exactly what the store configured.
        Role::query()->where('name', 'admin')->first()?->givePermissionTo($names->all());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<int, string> */
    private function pendingMigrations(): array
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));

        return $files->diff($ran)->values()->all();
    }

    private function waitForDatabase(int $seconds): bool
    {
        $deadline = time() + max(1, $seconds);
        do {
            try {
                DB::connection()->getPdo();

                return true;
            } catch (Throwable) {
                DB::purge();
                sleep(2);
            }
        } while (time() < $deadline);

        return false;
    }
}
