<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunBackup extends Command
{
    protected $signature = 'backup:run
        {--scheduled : Run as the scheduled daily backup (honors the enable setting)}
        {--if-due : Only back up if no scheduled backup completed in the last 23 hours (catch-up after the PC was off)}';

    protected $description = 'Create a PostgreSQL backup of the store database';

    public function handle(BackupService $backups): int
    {
        if ($this->option('scheduled') && ! settings('backup.schedule_enabled', false)) {
            $this->info('Scheduled backups are disabled in settings.');

            return self::SUCCESS;
        }

        if ($this->option('if-due') && Backup::query()->where('type', 'scheduled')->where('status', 'completed')
            ->where('created_at', '>', now()->subHours(23))->exists()) {
            $this->info('A recent scheduled backup exists; nothing to do.');

            return self::SUCCESS;
        }

        try {
            $backup = $backups->create($this->option('scheduled') ? 'scheduled' : 'manual');
            $this->info("Backup created: {$backup->filename} ({$backup->size} bytes)");

            return self::SUCCESS;
        } catch (BusinessException $e) {
            Log::error('Scheduled backup failed', ['error' => $e->getMessage()]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
