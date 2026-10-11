<?php

namespace Tests\Feature\Backups;

use App\Exceptions\BusinessException;
use App\Filament\Resources\Backups\Pages\ListBackups;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Backup/restore with pg_dump, pg_restore and psql faked. A real
 * PostgreSQL round trip lives in BackupIntegrationTest.
 */
class BackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        config(['backup.path' => $this->dir, 'backup.reconnect' => false, 'backup.files_path' => storage_path('framework/testing/files-'.uniqid())]);
        File::ensureDirectoryExists(config('backup.files_path'));
        file_put_contents(config('backup.files_path').'/logo.png', 'img');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory(config('backup.files_path'));
        parent::tearDown();
    }

    /** A separate connection entry (never actually opened: the tools are faked). */
    private function useBackupConnectionWithPassword(string $password): void
    {
        config([
            'database.connections.pgsql_backup' => array_merge(config('database.connections.pgsql'), ['password' => $password]),
            'backup.connection' => 'pgsql_backup',
        ]);
    }

    /** Fake the PostgreSQL tools; $fail = 'pg_dump' | 'psql' | null. */
    private function fakeTools(?string $fail = null): void
    {
        Process::fake(function (PendingProcess $process) use ($fail) {
            $cmd = $process->command;
            $bin = basename($cmd[0]);
            $fileArg = collect($cmd)->first(fn ($a) => str_starts_with($a, '--file='));

            if ($fail === $bin) {
                return Process::result('', "{$bin}: error: connection refused (password=secret)", 1);
            }
            if ($bin === 'pg_restore' && in_array('--list', $cmd, true)) {
                file_put_contents(substr($fileArg, 7), "; Archive created\n123; 1259 16400 TABLE public products khalid\n");

                return Process::result();
            }
            if ($bin === 'pg_dump' || ($bin === 'pg_restore' && $fileArg)) {
                file_put_contents(substr($fileArg, 7), "PGDMP\x01fake dump TABLE public products");
            }

            return Process::result();
        });
    }

    public function test_admin_creates_backup_outside_public_with_files_archive(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');

        $backup = app(BackupService::class)->create('manual', $admin);

        $this->assertSame('completed', $backup->status);
        $this->assertFileExists($this->dir.'/'.$backup->filename);
        $this->assertGreaterThan(0, $backup->size);
        $this->assertSame(64, strlen($backup->checksum));
        $this->assertFileExists($this->dir.'/'.$backup->files_archive);
        $this->assertStringStartsNotWith(public_path(), realpath($this->dir));
        $this->assertDatabaseHas('audit_logs', ['event' => 'backup_created', 'user_id' => $admin->id]);

        Process::assertRan(fn (PendingProcess $p) => basename($p->command[0]) === 'pg_dump'
            && in_array('--format=custom', $p->command, true)
            && ! collect($p->command)->contains(fn ($a) => str_contains($a, 'password')));
    }

    public function test_password_goes_through_environment_not_arguments(): void
    {
        $this->fakeTools();
        $this->useBackupConnectionWithPassword('s3cret-pass');

        app(BackupService::class)->create('manual', $this->userWithRole('admin'));

        Process::assertRan(fn (PendingProcess $p) => basename($p->command[0]) === 'pg_dump'
            && ($p->environment['PGPASSWORD'] ?? null) === 's3cret-pass'
            && ! str_contains(implode(' ', $p->command), 's3cret-pass'));
    }

    public function test_unauthorized_users_cannot_back_up_or_download(): void
    {
        $this->fakeTools();
        $backup = app(BackupService::class)->create('manual', $this->userWithRole('admin'));

        foreach (['manager', 'cashier'] as $role) {
            $user = $this->userWithRole($role);
            try {
                app(BackupService::class)->create('manual', $user);
                $this->fail("{$role} created a backup");
            } catch (AuthorizationException) {
            }
            $this->actingAs($user)->get('/admin/backups')->assertForbidden();
            $this->actingAs($user)->get(route('backups.download', $backup))->assertForbidden();
        }
    }

    public function test_backup_files_are_not_publicly_accessible(): void
    {
        $this->fakeTools();
        $backup = app(BackupService::class)->create('manual', $this->userWithRole('admin'));

        auth()->logout();
        $this->get(route('backups.download', $backup))->assertRedirect('/admin/login');
        $this->assertContains($this->get('/storage/'.$backup->filename)->status(), [403, 404]);
        $this->assertContains($this->get('/'.$backup->filename)->status(), [403, 404]);
    }

    public function test_admin_downloads_backup_as_stream(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');
        $backup = app(BackupService::class)->create('manual', $admin);

        $response = $this->actingAs($admin)->get(route('backups.download', $backup));
        $response->assertOk()->assertDownload($backup->filename);
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
    }

    public function test_failed_backup_is_logged_and_not_reported_as_success(): void
    {
        $this->fakeTools('pg_dump');
        $this->useBackupConnectionWithPassword('secret');
        Log::spy();

        try {
            app(BackupService::class)->create('manual', $this->userWithRole('admin'));
            $this->fail('failure reported as success');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('فشل إنشاء النسخة', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage(), 'password masked');
        }

        $backup = Backup::query()->firstOrFail();
        $this->assertSame('failed', $backup->status);
        $this->assertSame([], glob($this->dir.'/*.dump'), 'partial file removed');
        Log::shouldHaveReceived('error')->withArgs(fn ($msg) => str_contains($msg, 'Database backup failed'));
    }

    public function test_invalid_backup_files_are_rejected(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');
        $service = app(BackupService::class);

        foreach ([
            UploadedFile::fake()->createWithContent('db.sql', 'SELECT 1;'),            // wrong extension
            UploadedFile::fake()->createWithContent('db.dump', 'not a postgres dump'),  // wrong magic bytes
        ] as $file) {
            try {
                $service->importUpload($file, $admin);
                $this->fail('invalid backup accepted');
            } catch (BusinessException) {
            }
        }

        $this->assertSame(0, Backup::query()->count());
    }

    public function test_valid_upload_is_stored_under_a_generated_name(): void
    {
        $this->fakeTools();
        $backup = app(BackupService::class)->importUpload(
            UploadedFile::fake()->createWithContent('../../etc/passwd.dump', "PGDMP\x01x"), $this->userWithRole('admin'));

        $this->assertMatchesRegularExpression('/^retailpos-\d{8}-\d{6}-uploaded-[a-z0-9]{6}\.dump$/', $backup->filename);
        $this->assertFileExists($this->dir.'/'.$backup->filename);
    }

    public function test_paths_cannot_escape_the_backup_directory(): void
    {
        $service = app(BackupService::class);
        File::ensureDirectoryExists($this->dir);

        foreach (['../.env', '/etc/passwd', '..\\x.dump', 'a/b.dump', ''] as $name) {
            $this->assertNull($service->safePath($name), $name);
        }
    }

    public function test_restore_requires_permission_and_exact_confirmation(): void
    {
        $this->fakeTools();
        $backup = app(BackupService::class)->create('manual', $this->userWithRole('admin'));
        $before = Backup::query()->count();

        try {
            app(BackupService::class)->restore($backup, $this->userWithRole('manager'), BackupService::CONFIRMATION);
            $this->fail('manager restored');
        } catch (AuthorizationException) {
        }

        try {
            app(BackupService::class)->restore($backup, $this->userWithRole('admin'), 'نعم');
            $this->fail('restore without the confirmation phrase');
        } catch (BusinessException $e) {
            $this->assertStringContainsString(BackupService::CONFIRMATION, $e->getMessage());
        }

        Process::assertDidntRun(fn (PendingProcess $p) => basename($p->command[0]) === 'psql');
        $this->assertSame($before, Backup::query()->count(), 'no safety backup either');
    }

    public function test_failed_restore_keeps_data_logs_and_does_not_report_success(): void
    {
        $this->fakeTools('psql');
        $admin = $this->userWithRole('admin');
        $backup = app(BackupService::class)->create('manual', $admin);

        try {
            app(BackupService::class)->restore($backup, $admin, BackupService::CONFIRMATION);
            $this->fail('failed restore reported success');
        } catch (BusinessException $e) {
            $this->assertStringContainsString('فشلت الاستعادة', $e->getMessage());
        }

        $this->assertTrue(Backup::query()->where('type', 'pre_restore')->where('status', 'completed')->exists(), 'safety backup taken first');
        $this->assertDatabaseHas('audit_logs', ['event' => 'database_restore_failed']);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'database_restored']);
        $this->assertFalse(BackupService::restoreInProgress(), 'write block released');
        $this->assertSame([], glob($this->dir.'/restore-*.sql'), 'temporary SQL removed');
    }

    public function test_successful_restore_flow_and_unrelated_files_untouched(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');
        $backup = app(BackupService::class)->create('manual', $admin);
        file_put_contents($this->dir.'/notes.txt', 'keep me');

        $result = app(BackupService::class)->restore($backup, $admin, ' '.BackupService::CONFIRMATION.' ');

        $this->assertStringContainsString('pre_restore', $result['safety_backup']);
        $this->assertArrayHasKey('sales', $result['counts']);
        Process::assertRan(fn (PendingProcess $p) => basename($p->command[0]) === 'psql'
            && in_array('--single-transaction', $p->command, true)
            && in_array('--set=ON_ERROR_STOP=1', $p->command, true));
        $this->assertFileExists($this->dir.'/notes.txt');
        $this->assertFileExists(config('backup.files_path').'/logo.png');
        $this->assertFileExists(base_path('.env.example'));
        $this->assertTrue(AuditLog::query()->where('event', 'database_restored')->exists());
    }

    public function test_tampered_backup_is_refused(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');
        $backup = app(BackupService::class)->create('manual', $admin);
        file_put_contents($this->dir.'/'.$backup->filename, "PGDMP\x01tampered");

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('بصمة الملف');
        app(BackupService::class)->restore($backup, $admin, BackupService::CONFIRMATION);
    }

    public function test_writes_are_blocked_while_restore_runs(): void
    {
        Cache::put(BackupService::RESTORE_FLAG, ['by' => 'x'], 60);
        $this->actingAs($this->userWithRole('admin'));

        $this->post('/livewire/update', [])->assertStatus(503);
        $this->postJson('/api/v1/sales', [])->assertStatus(503);
        $this->get('/admin')->assertOk();

        Cache::forget(BackupService::RESTORE_FLAG);
    }

    public function test_scheduled_command_respects_setting_and_retention(): void
    {
        $this->fakeTools();

        $this->artisan('backup:run --scheduled')->expectsOutputToContain('disabled')->assertSuccessful();
        Process::assertNothingRan();

        settings()->setMany(['backup.schedule_enabled' => true, 'backup.retention_days' => 7]);
        $old = Backup::query()->create(['filename' => 'retailpos-20200101-020000-scheduled.dump', 'type' => 'scheduled', 'status' => 'completed']);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $manual = Backup::query()->create(['filename' => 'retailpos-20200101-020000-manual.dump', 'type' => 'manual', 'status' => 'completed']);
        $manual->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->artisan('backup:run --scheduled')->assertSuccessful();

        $this->assertTrue(Backup::query()->where('type', 'scheduled')->where('status', 'completed')->whereDate('created_at', today())->exists());
        $this->assertModelMissing($old);
        $this->assertModelExists($manual);
    }

    public function test_page_reports_scheduler_truthfully(): void
    {
        $admin = $this->userWithRole('admin');
        settings()->set('backup.schedule_enabled', true);
        Cache::forget('scheduler.heartbeat');

        Livewire::actingAs($admin)->test(ListBackups::class)->assertSee('مجدول النظام لا يعمل');

        Cache::forever('scheduler.heartbeat', now()->toIso8601String());
        Livewire::actingAs($admin)->test(ListBackups::class)->assertSee('المجدول يعمل');
    }

    public function test_backup_page_actions_for_admin(): void
    {
        $this->fakeTools();
        $admin = $this->userWithRole('admin');

        Livewire::actingAs($admin)->test(ListBackups::class)->callAction('create')->assertNotified('تم إنشاء النسخة الاحتياطية');
        $backup = Backup::query()->firstOrFail();

        Livewire::actingAs($admin)->test(ListBackups::class)
            ->callTableAction('delete', $backup)
            ->assertNotified('تم حذف النسخة');
        $this->assertFileDoesNotExist($this->dir.'/'.$backup->filename);
    }
}
