<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Services\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--scheduled : Run as the scheduled daily backup (honors the enable setting)}';

    protected $description = 'Create a PostgreSQL backup of the store database';

    public function handle(BackupService $backups): int
    {
        if ($this->option('scheduled') && ! settings('backup.schedule_enabled', false)) {
            $this->info('Scheduled backups are disabled in settings.');

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
