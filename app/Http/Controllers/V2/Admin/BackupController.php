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
            'remote_disk' => 'nullable|string|in:google_cloud,ftp',
        ]);

        try {
            return $this->success($backups->createDatabaseBackup((bool) $request->boolean('upload'), [
                'trigger' => 'manual',
                'remote_disk' => $request->input('remote_disk'),
            ]));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function settings(BackupService $backups)
    {
        return $this->success($backups->settings());
    }

    public function updateSettings(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'enabled' => 'nullable|boolean',
            'time' => 'required|string|regex:/^([01]\d|2[0-3]):([0-5]\d)$/',
            'keep' => 'required|integer|min:1|max:365',
            'upload' => 'nullable|boolean',
            'remote_disk' => 'nullable|string|in:google_cloud,ftp',
        ]);

        try {
            return $this->success($backups->updateSettings($data));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function updateRemoteStorage(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'google_cloud' => 'nullable|array',
            'google_cloud.bucket' => 'nullable|string|max:255',
            'google_cloud.prefix' => 'nullable|string|max:255',
            'google_cloud.credentials_json' => 'nullable|string|max:60000',
            'google_cloud.clear_credentials' => 'nullable|boolean',
            'ftp' => 'nullable|array',
            'ftp.host' => 'nullable|string|max:255',
            'ftp.port' => 'nullable|integer|min:1|max:65535',
            'ftp.username' => 'nullable|string|max:255',
            'ftp.password' => 'nullable|string|max:1024',
            'ftp.root' => 'nullable|string|max:255',
            'ftp.ssl' => 'nullable|boolean',
            'ftp.passive' => 'nullable|boolean',
            'ftp.timeout' => 'nullable|integer|min:1|max:300',
            'ftp.clear_password' => 'nullable|boolean',
        ]);

        try {
            return $this->success($backups->updateRemoteStorageSettings($data));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function testRemoteStorage(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'disk' => 'required|string|in:google_cloud,ftp',
        ]);

        try {
            return $this->success($backups->testRemoteStorage((string) $data['disk']));
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

    public function verify(Request $request, BackupService $backups)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ]);

        try {
            return $this->success($backups->verifyBackup((int) $request->input('id')));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function restorePreflight(Request $request, BackupService $backups)
    {
        $request->validate([
            'id' => 'required|integer|min:1',
        ]);

        try {
            return $this->success($backups->restorePreflight((int) $request->input('id')));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
        }
    }

    public function restoreDrill(Request $request, BackupService $backups)
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'status' => 'required|string|in:passed,failed,incomplete',
            'environment' => 'required|string|in:local,staging,production_rehearsal',
            'note' => 'nullable|string|max:1000',
            'operator' => 'nullable|string|max:120',
        ]);

        try {
            return $this->success($backups->recordRestoreDrill((int) $data['id'], $data));
        } catch (Throwable $e) {
            return $this->fail([500001, $e->getMessage()]);
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
