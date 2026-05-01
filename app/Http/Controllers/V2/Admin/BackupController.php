<?php

namespace App\Http\Controllers\V2\Admin;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Services\Backup\BackupService;
use Illuminate\Http\Request;
use Throwable;

class BackupController extends Controller
{
    public function overview(BackupService $backups)
    {
        return $this->success($backups->overview());
    }

    public function fetch(Request $request, BackupService $backups)
    {
        $page = $backups->paginate([
            'current' => $request->input('current', $request->input('page', 1)),
            'page_size' => $request->input('page_size', $request->input('pageSize', 20)),
            'status' => $request->input('status'),
            'type' => $request->input('type'),
        ]);

        return $this->paginate(
            $page,
            array_map(fn($record) => $backups->formatRecord($record), $page->items())
        );
    }

    public function create(Request $request, BackupService $backups)
    {
        $request->validate([
            'upload' => 'nullable|boolean',
        ]);

        try {
            return $this->success($backups->createDatabaseBackup((bool) $request->boolean('upload')));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function download(int $id, BackupService $backups)
    {
        try {
            $record = $backups->findDownloadable($id);
            return response()->download(
                $backups->localPath($record),
                $record->filename,
                ['Content-Type' => 'application/gzip']
            );
        } catch (Throwable $e) {
            return $this->fail(ResponseEnum::CLIENT_NOT_FOUND_ERROR, null, $e->getMessage());
        }
    }

    public function drop(Request $request, BackupService $backups)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ]);

        try {
            $backups->deleteBackup((int) $request->input('id'));
            return $this->success(true);
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function cleanup(Request $request, BackupService $backups)
    {
        $request->validate([
            'keep' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            return $this->success($backups->pruneLocalBackups((int) $request->input('keep', 7)));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }
}
