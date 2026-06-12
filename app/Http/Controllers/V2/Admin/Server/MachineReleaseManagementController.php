<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin\Server;

use App\Http\Controllers\Controller;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class MachineReleaseManagementController extends Controller
{
    public function __construct(private readonly MachineReleaseDistributionService $distribution)
    {
    }

    public function fetch(Request $request): JsonResponse
    {
        return $this->success([
            'items' => $this->distribution->listLocalReleases(),
            'components' => ['kelinode-rs', 'keli-core-rs'],
            'platforms' => [MachineReleaseDistributionService::PLATFORM_LINUX_X86_64],
        ]);
    }

    public function upload(Request $request): JsonResponse
    {
        $params = $request->validate([
            'component' => 'required|string|max:32',
            'version' => 'required|string|max:64',
            'platform' => 'nullable|string|max:32',
            'is_default' => 'nullable|boolean',
            'manifest' => 'required|file|max:1024',
            'archive' => 'required|file|max:204800',
        ]);

        try {
            $release = $this->distribution->storeLocalRelease(
                (string) $params['component'],
                (string) $params['version'],
                (string) ($params['platform'] ?? MachineReleaseDistributionService::PLATFORM_LINUX_X86_64),
                $request->file('manifest'),
                $request->file('archive'),
                (bool) ($params['is_default'] ?? false)
            );

            return $this->success($release->toAdminPayload());
        } catch (InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([500, '上传版本失败']);
        }
    }

    public function setDefault(Request $request): JsonResponse
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        $release = ServerMachineRelease::find((int) $params['id']);
        if (!$release) {
            return $this->fail([400202, '版本不存在']);
        }

        return $this->success($this->distribution->setDefaultLocalRelease($release)->toAdminPayload());
    }

    public function drop(Request $request): JsonResponse
    {
        $params = $request->validate([
            'id' => 'required|integer',
        ]);

        $release = ServerMachineRelease::find((int) $params['id']);
        if (!$release) {
            return $this->fail([400202, '版本不存在']);
        }

        try {
            $this->distribution->deleteLocalRelease($release);
            return $this->success(true);
        } catch (InvalidArgumentException $e) {
            return $this->fail([422, $e->getMessage()]);
        } catch (\Throwable $e) {
            report($e);
            return $this->fail([500, '删除版本失败']);
        }
    }
}

