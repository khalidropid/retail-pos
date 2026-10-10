<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Services\AuditLogger;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Authorized, streamed download of a backup. Files are never reachable
 * through a public URL; the path is resolved only from the database row
 * and checked to be inside the backup directory.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(Request $request, Backup $backup, BackupService $backups, AuditLogger $audit): BinaryFileResponse
    {
        abort_unless($request->user()?->can('backups.manage'), 403);
        abort_unless($backup->isCompleted(), 404);

        $name = $request->query('part') === 'files' ? $backup->files_archive : $backup->filename;
        abort_if(blank($name), 404);

        $path = $backups->safePath($name);
        abort_if($path === null, 404);

        $audit->log('backup_downloaded', $backup, null, ['file' => $name]);

        // BinaryFileResponse streams from disk; the file is never loaded into memory.
        return response()->download($path, $name, ['Content-Type' => 'application/octet-stream']);
    }
}
