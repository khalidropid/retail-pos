<?php

namespace Tests\Feature\Backups;

use App\Models\Backup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * desktop:setup against a real, isolated PostgreSQL database.
 *   RUN_BACKUP_INTEGRATION=1 php artisan test --filter=DesktopSetupIntegrationTest
 */
class DesktopSetupIntegrationTest extends TestCase
{
    private const DB = 'retail_pos_setup_it';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('RUN_BACKUP_INTEGRATION')) {
            $this->markTestSkipped('Set RUN_BACKUP_INTEGRATION=1 to run.');
        }

        $this->tool('dropdb', ['--if-exists', self::DB]);
        $this->tool('createdb', [self::DB]);
        config([
            'database.connections.setup_it' => array_merge(config('database.connections.pgsql'), ['database' => self::DB]),
            'database.default' => 'setup_it',
            'backup.connection' => 'setup_it',
            'backup.path' => $this->dir = storage_path('framework/testing/setup-it-'.uniqid()),
        ]);
    }

    protected function tearDown(): void
    {
        if (env('RUN_BACKUP_INTEGRATION')) {
            config(['database.default' => 'pgsql']);
            DB::purge('setup_it');
            $this->tool('dropdb', ['--if-exists', self::DB]);
            File::deleteDirectory($this->dir ?? '');
        }
        parent::tearDown();
    }

    private function tool(string $bin, array $args): void
    {
        $c = config('database.connections.pgsql');
        Process::env(['PGPASSWORD' => (string) ($c['password'] ?? '')])
            ->run(array_merge([$bin, '--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username']], $args));
    }

    public function test_fresh_install_then_upgrade_preserves_data(): void
    {
        $credentials = $this->dir.'-login.txt';
        File::ensureDirectoryExists($this->dir);

        // 1) Empty database -> initialized.
        $this->assertSame(0, Artisan::call('desktop:setup', ['--credentials-file' => $credentials, '--wait' => 5]));
        $db = DB::connection('setup_it');
        $this->assertSame(1, $db->table('users')->count());
        $this->assertFileExists($credentials);
        $this->assertStringContainsString('كلمة المرور المؤقتة', file_get_contents($credentials));
        $this->assertSame(0, $db->table('products')->count(), 'no demo data in a real install');
        $this->assertSame('true', $db->table('settings')->where('key', 'backup.schedule_enabled')->value('value'), 'daily backup on for new installs');

        // Business data entered by the store.
        $unit = $db->table('units')->value('id');
        $db->table('products')->insert(['name' => 'منتج حقيقي', 'name_normalized' => 'منتج حقيقي', 'sku' => 'REAL-1', 'unit_id' => $unit,
            'cost_price' => 1, 'sale_price' => 2, 'tax_rate' => 0, 'stock_quantity' => 5, 'created_at' => now(), 'updated_at' => now()]);

        // The store turns the daily backup off; later runs must respect that.
        $db->table('settings')->where('key', 'backup.schedule_enabled')->update(['value' => 'false']);

        // 2) Re-running (same version) changes nothing.
        $this->assertSame(0, Artisan::call('desktop:setup', ['--wait' => 5]));
        $this->assertSame(1, $db->table('products')->count());
        $this->assertSame(1, $db->table('users')->count(), 'no second admin');

        // 3) Upgrade: a newer migration is pending -> backup first, then migrate, data kept.
        $last = $db->table('migrations')->orderByDesc('id')->value('migration');
        $db->table('migrations')->where('migration', $last)->delete();
        $db->getSchemaBuilder()->dropIfExists('backups');

        $this->assertSame(0, Artisan::call('desktop:setup', ['--wait' => 5]), Artisan::output());
        $this->assertTrue($db->getSchemaBuilder()->hasTable('backups'));
        $this->assertSame('REAL-1', $db->table('products')->value('sku'));
        $this->assertNotEmpty(glob($this->dir.'/retailpos-*-pre_upgrade-*.dump'), 'verified backup taken before migrating');
        $this->assertSame('false', $db->table('settings')->where('key', 'backup.schedule_enabled')->value('value'), 'store choice kept on upgrade');
    }

    public function test_refuses_a_database_with_foreign_tables(): void
    {
        DB::connection('setup_it')->statement('CREATE TABLE someone_elses_data (id int)');

        $this->assertSame(3, Artisan::call('desktop:setup', ['--wait' => 5]));
        $this->assertTrue(DB::connection('setup_it')->getSchemaBuilder()->hasTable('someone_elses_data'));
        $this->assertFalse(DB::connection('setup_it')->getSchemaBuilder()->hasTable('users'));
    }

    public function test_unreachable_database_reports_clearly(): void
    {
        config(['database.connections.setup_it.port' => 1]);
        DB::purge('setup_it');

        $this->assertSame(2, Artisan::call('desktop:setup', ['--wait' => 1]));
        $this->assertStringContainsString('DATABASE_UNREACHABLE', Artisan::output());
    }
}
