<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketAttachmentService
{
    /**
     * @param array<int, UploadedFile> $files
     * @return array<int, array{disk:string,path:string,mime:string,size:int,width:int|null,height:int|null}>
     */
    public function storeUploadedImages(array $files, int $ticketId, int $ticketMessageId): array
    {
        $baseDir = trim((string) config('tickets.attachments.base_dir', 'ticket_attachments'), '/');
        $disk = (string) config('tickets.attachments.disk', 'local');
        $dir = $baseDir . '/' . $ticketId . '/' . $ticketMessageId;

        Storage::disk($disk)->makeDirectory($dir);

        $stored = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }
            $stored[] = $this->storeSingleImage($file, $disk, $dir);
        }
        return $stored;
    }

    /**
     * @return array{disk:string,path:string,mime:string,size:int,width:int|null,height:int|null}
     */
    private function storeSingleImage(UploadedFile $file, string $disk, string $dir): array
    {
        $quality = (int) config('tickets.attachments.webp_quality', 80);
        $maxDimension = (int) config('tickets.attachments.max_dimension', 1920);

        $uuid = (string) Str::uuid();
        $targetRelativePath = $dir . '/' . $uuid . '.webp';

        $sourcePath = $file->getRealPath();
        if (!$sourcePath) {
            throw new \RuntimeException('Invalid uploaded file');
        }

        $targetAbsolutePath = Storage::disk($disk)->path($targetRelativePath);

        $converted = $this->convertToWebp($sourcePath, $targetAbsolutePath, $maxDimension, $quality);
        if (!$converted) {
            Log::warning('Ticket attachment webp conversion failed, storing original', [
                'mime' => $file->getMimeType(),
                'name' => $file->getClientOriginalName(),
            ]);

            $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
            $fallbackRelativePath = $dir . '/' . $uuid . '.' . $ext;
            Storage::disk($disk)->putFileAs($dir, $file, $uuid . '.' . $ext);
            return $this->buildMeta($disk, $fallbackRelativePath);
        }

        return $this->buildMeta($disk, $targetRelativePath);
    }

    private function buildMeta(string $disk, string $relativePath): array
    {
        $absolutePath = Storage::disk($disk)->path($relativePath);

        $size = @filesize($absolutePath);
        $size = is_int($size) && $size >= 0 ? $size : 0;

        $mime = 'application/octet-stream';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($absolutePath);
            if (is_string($detected) && $detected) {
                $mime = $detected;
            }
        }

        $width = null;
        $height = null;
        $dim = @getimagesize($absolutePath);
        if (is_array($dim)) {
            $width = isset($dim[0]) ? (int) $dim[0] : null;
            $height = isset($dim[1]) ? (int) $dim[1] : null;
        }

        return [
            'disk' => $disk,
            'path' => $relativePath,
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function convertToWebp(string $sourcePath, string $targetAbsolutePath, int $maxDimension, int $quality): bool
    {
        if ($this->convertWithImagick($sourcePath, $targetAbsolutePath, $maxDimension, $quality)) {
            return true;
        }
        return $this->convertWithGd($sourcePath, $targetAbsolutePath, $maxDimension, $quality);
    }

    private function convertWithImagick(string $sourcePath, string $targetAbsolutePath, int $maxDimension, int $quality): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            $img = new \Imagick($sourcePath);
            if (method_exists($img, 'autoOrient')) {
                $img->autoOrient();
            } elseif (method_exists($img, 'autoOrientImage')) {
                $img->autoOrientImage();
            }

            $width = (int) $img->getImageWidth();
            $height = (int) $img->getImageHeight();
            if ($maxDimension > 0 && ($width > $maxDimension || $height > $maxDimension)) {
                $scale = $width >= $height ? ($maxDimension / max(1, $width)) : ($maxDimension / max(1, $height));
                $newW = max(1, (int) round($width * $scale));
                $newH = max(1, (int) round($height * $scale));
                $img->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1, true);
            }

            $img->stripImage();
            $img->setImageFormat('webp');
            $img->setImageCompressionQuality(max(0, min(100, $quality)));
            $img->writeImage($targetAbsolutePath);
            $img->clear();
            $img->destroy();
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    private function convertWithGd(string $sourcePath, string $targetAbsolutePath, int $maxDimension, int $quality): bool
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return false;
        }

        $info = @getimagesize($sourcePath);
        $type = is_array($info) && isset($info[2]) ? (int) $info[2] : 0;
        if (!$type) {
            return false;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_PNG => @imagecreatefrompng($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : @imagecreatefromstring((string) file_get_contents($sourcePath)),
            default => false,
        };
        if (!$src || !is_resource($src) && !($src instanceof \GdImage)) {
            return false;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);
        $dstW = $srcW;
        $dstH = $srcH;

        if ($maxDimension > 0 && ($srcW > $maxDimension || $srcH > $maxDimension)) {
            $scale = $srcW >= $srcH ? ($maxDimension / max(1, $srcW)) : ($maxDimension / max(1, $srcH));
            $dstW = max(1, (int) round($srcW * $scale));
            $dstH = max(1, (int) round($srcH * $scale));
        }

        $dst = imagecreatetruecolor($dstW, $dstH);
        if (!$dst) {
            imagedestroy($src);
            return false;
        }

        imagealphablending($dst, false);
        imagesavealpha($dst, true);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        $ok = imagewebp($dst, $targetAbsolutePath, max(0, min(100, $quality)));

        imagedestroy($src);
        imagedestroy($dst);

        return (bool) $ok;
    }
}
