<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Services\ServerMachine\MachineReleaseDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MachineReleaseController extends Controller
{
    public function __construct(private readonly MachineReleaseDistributionService $distribution)
    {
    }

    public function installScript(Request $request): Response
    {
        $path = resource_path('server-machine/kelinode-rs-install.sh');
        if (!is_file($path)) {
            return response('installer not found', 404);
        }

        return response((string) file_get_contents($path), 200, [
            'Content-Type' => 'text/x-shellscript; charset=UTF-8',
        ]);
    }

    public function latest(Request $request, string $component, string $platform): JsonResponse
    {
        if (!$this->distribution->validateMachine($request)) {
            return response()->json(['message' => 'Invalid machine credentials'], 403);
        }

        $component = $this->distribution->normalizeComponent($component);
        $platform = $this->distribution->normalizePlatform($platform);
        if (!$component || !$platform) {
            return response()->json(['message' => 'Invalid release target'], 404);
        }

        $version = $this->distribution->latestLocalVersion($component, $platform);
        return response()->json([
            'latest_version' => $version,
            'component' => $component,
            'platform' => $platform,
            'source' => 'panel',
            'error' => $version ? null : 'no_local_release',
        ], $version ? 200 : 404);
    }

    public function manifest(Request $request, string $component, string $version, string $platform): Response
    {
        if (!$this->distribution->validateMachine($request)) {
            return response('Invalid machine credentials', 403);
        }

        $component = $this->distribution->normalizeComponent($component);
        $version = $this->distribution->normalizeVersion($version);
        $platform = $this->distribution->normalizePlatform($platform);
        if (!$component || !$version || !$platform) {
            return response('Invalid release target', 404);
        }

        $path = $this->distribution->manifestPath($component, $version, $platform);
        if (!Storage::disk('local')->exists($path)) {
            return response('Manifest not found', 404);
        }

        return response(Storage::disk('local')->get($path), 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function archive(Request $request, string $component, string $version, string $platform): StreamedResponse|Response
    {
        if (!$this->distribution->validateMachine($request)) {
            return response('Invalid machine credentials', 403);
        }

        $component = $this->distribution->normalizeComponent($component);
        $version = $this->distribution->normalizeVersion($version);
        $platform = $this->distribution->normalizePlatform($platform);
        if (!$component || !$version || !$platform) {
            return response('Invalid release target', 404);
        }

        $path = $this->distribution->archivePath($component, $version, $platform);
        if (!Storage::disk('local')->exists($path)) {
            return response('Archive not found', 404);
        }

        return response()->streamDownload(function () use ($path): void {
            echo Storage::disk('local')->get($path);
        }, basename($path), [
            'Content-Type' => 'application/gzip',
        ]);
    }
}
