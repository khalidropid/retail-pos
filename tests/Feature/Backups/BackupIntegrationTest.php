<?php

namespace Tests\Feature\Backups;

use App\Exceptions\BusinessException;
use App\Services\BackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Real pg_dump / psql round trip against an isolated, throw-away database.
 *
 * Skipped by default. Run it with:
 *   RUN_BACKUP_INTEGRATION=1 php artisan test --filter=BackupIntegrationTest
 *
 * Needs the PostgreSQL client tools (pg_dump, pg_restore, psql, createdb,
 * dropdb) and a DB user allowed to create databases. It never touches the
 * application or test databases: everything happens in retail_pos_backup_it.
 */
class BackupIntegrationTest extends TestCase
{
    private const DB = 'retail_pos_backup_it';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('RUN_BACKUP_INTEGRATION')) {
            $this->markTestSkipped('Set RUN_BACKUP_INTEGRATION=1 to run the real PostgreSQL backup/restore test.');
        }

        $base = config('database.connections.pgsql');
        config([
            'database.connections.backup_it' => array_merge($base, ['database' => self::DB]),
            'backup.connection' => 'backup_it',
            'backup.path' => $this->dir = storage_path('framework/testing/backup-it-'.uniqid()),
            'backup.files_path' => storage_path('framework/testing/backup-it-files'),
        ]);

        $this->dropDatabase();
        $this->tool('createdb', [self::DB]);
        Artisan::call('migrate', ['--database' => 'backup_it', '--force' => true]);
    }

    protected function tearDown(): void
    {
        if (env('RUN_BACKUP_INTEGRATION')) {
            DB::purge('backup_it');
            $this->dropDatabase();
            File::deleteDirectory($this->dir ?? '');
        }
        parent::tearDown();
    }

    private function tool(string $bin, array $args): void
    {
        $base = config('database.connections.pgsql');
        $result = Process::env(['PGPASSWORD' => (string) ($base['password'] ?? '')])->run(array_merge(
            [$bin, '--host='.$base['host'], '--port='.$base['port'], '--username='.$base['username']], $args));
        if (! $result->successful() && $bin !== 'dropdb') {
            $this->fail("{$bin} failed: ".$result->errorOutput());
        }
    }

    private function dropDatabase(): void
    {
        $this->tool('dropdb', ['--if-exists', self::DB]);
    }

    public function test_real_backup_and_restore_round_trip(): void
    {
        $it = DB::connection('backup_it');
        $it->table('users')->insert(['name' => 'مدير', 'email' => 'it@pos.test', 'password' => Hash::make('x'), 'created_at' => now(), 'updated_at' => now()]);
        $unit = $it->table('units')->insertGetId(['name' => 'قطعة', 'short_name' => 'قطعة', 'allow_decimal' => false, 'created_at' => now(), 'updated_at' => now()]);
        $it->table('products')->insert(['name' => 'عطر', 'name_normalized' => 'عطر', 'sku' => 'IT-1', 'unit_id' => $unit,
            'cost_price' => 1, 'sale_price' => 2, 'tax_rate' => 0, 'stock_quantity' => 7, 'created_at' => now(), 'updated_at' => now()]);

        $service = app(BackupService::class);
        $backup = $service->create('manual');
        $this->assertSame('completed', $backup->status);
        $this->assertSame('PGDMP', file_get_contents($this->dir.'/'.$backup->filename, false, null, 0, 5));

        // Data changes after the backup, plus a table a "newer migration" added.
        $it->table('products')->where('sku', 'IT-1')->delete();
        $it->statement('CREATE TABLE added_after_backup (id int)');
        $this->assertSame(0, $it->table('products')->count());

        $result = $service->restore($backup, $this->userWithRole('admin'), BackupService::CONFIRMATION);

        $it = DB::connection('backup_it');
        $this->assertSame('7.000', $it->table('products')->where('sku', 'IT-1')->value('stock_quantity'));
        $this->assertFalse($it->getSchemaBuilder()->hasTable('added_after_backup'), 'schema matches the backup exactly');
        $this->assertTrue($it->getSchemaBuilder()->hasTable('backups'), 'pending migrations re-applied');
        $this->assertSame(1, $result['counts']['users']);
        $this->assertFileExists($this->dir.'/'.$result['safety_backup']);

        // The application's own database was not touched.
        $this->assertTrue(DB::connection('pgsql')->getSchemaBuilder()->hasTable('products'));
    }

    public function test_corrupt_dump_is_rejected_by_pg_restore(): void
    {
        File::ensureDirectoryExists($this->dir);
        $path = $this->dir.'/corrupt.dump';
        file_put_contents($path, 'PGDMP'.random_bytes(200));

        $this->expectException(BusinessException::class);
        app(BackupService::class)->validateDump($path);
    }
}
