<?php

namespace App\Http\Controllers;

use App\Services\DatabaseBackupService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function download(Request $request, DatabaseBackupService $backup): Response|StreamedResponse
    {
        if (! $request->user()?->hasPermission('can_run_daily_backup')) {
            abort(403);
        }

        $stem = $backup->suggestedFilenameStem();

        if (config('database.default') === 'sqlite') {
            $path = $backup->sqliteDatabasePath();
            if (! $path || ! is_readable($path)) {
                abort(503, 'Backup requires a file-based SQLite database or MySQL. In-memory SQLite cannot be exported this way.');
            }

            Audit::log($request->user()->id, 'backup.downloaded', 'database', null, ['type' => 'sqlite']);

            return response()->download($path, $stem.'.sqlite', [
                'Content-Type' => 'application/octet-stream',
            ]);
        }

        if (config('database.default') === 'mysql') {
            Audit::log($request->user()->id, 'backup.downloaded', 'database', null, ['type' => 'mysql']);

            return response()->streamDownload(function () use ($backup) {
                $backup->streamMysqlDump(function (string $chunk): void {
                    echo $chunk;
                });
            }, $stem.'.sql', [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        abort(503, 'This database driver is not supported for backup downloads.');
    }
}
