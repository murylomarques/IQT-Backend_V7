<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EvidenceFileService
{
    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const FILE_MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
    ];

    private const MAX_DIMENSION = 1280;
    private const WEBP_QUALITY = 70;

    public static function storeUploaded(UploadedFile $file, string $directory): string
    {
        $contents = file_get_contents($file->getRealPath());
        $mimeType = $file->getMimeType();

        return self::storeContents($contents, $directory, $mimeType);
    }

    public static function storeContents(string $contents, string $directory, ?string $mimeType = null): string
    {
        $detectedMime = self::detectMime($contents) ?? $mimeType;

        if ($detectedMime && self::isSupportedImage($detectedMime)) {
            $optimizedPath = self::storeOptimizedWebp($contents, $directory);

            if ($optimizedPath) {
                return $optimizedPath;
            }
        }

        $extension = self::extensionForMime($detectedMime);

        if (!$extension) {
            throw new \InvalidArgumentException('Tipo de arquivo não permitido.');
        }

        $path = self::buildPath($directory, $extension);

        Storage::disk('public')->put($path, $contents);

        return $path;
    }

    public static function delete(?string $path): void
    {
        $normalizedPath = self::normalizePath($path);

        if ($normalizedPath) {
            Storage::disk('public')->delete($normalizedPath);
        }
    }

    public static function normalizePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#^/?storage/#', '', $path);
        $path = ltrim($path, '/');

        return $path !== '' ? $path : null;
    }

    private static function isSupportedImage(?string $mimeType): bool
    {
        return isset(self::IMAGE_MIME_EXTENSIONS[$mimeType]);
    }

    private static function extensionForMime(?string $mimeType): ?string
    {
        return self::IMAGE_MIME_EXTENSIONS[$mimeType]
            ?? self::FILE_MIME_EXTENSIONS[$mimeType]
            ?? null;
    }

    private static function detectMime(string $contents): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if (!$fileInfo) {
            return null;
        }

        $mimeType = finfo_buffer($fileInfo, $contents) ?: null;
        finfo_close($fileInfo);

        return $mimeType;
    }

    private static function canOptimizeWithGd(): bool
    {
        return function_exists('imagecreatefromstring')
            && function_exists('imagecreatetruecolor')
            && function_exists('imagecopyresampled')
            && function_exists('imagewebp');
    }

    private static function storeOptimizedWebp(string $contents, string $directory): ?string
    {
        if (!self::canOptimizeWithGd()) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if (!$source) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            return null;
        }

        $ratio = min(1, self::MAX_DIMENSION / $sourceWidth, self::MAX_DIMENSION / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $ratio));
        $targetHeight = max(1, (int) round($sourceHeight * $ratio));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        imagealphablending($target, false);
        imagesavealpha($target, true);

        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        ob_start();
        $success = imagewebp($target, null, self::WEBP_QUALITY);
        $webpContents = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (!$success || !$webpContents) {
            return null;
        }

        $path = self::buildPath($directory, 'webp');
        Storage::disk('public')->put($path, $webpContents);

        return $path;
    }

    private static function buildPath(string $directory, string $extension): string
    {
        $directory = trim($directory, '/');

        return $directory . '/' . uniqid('evidencia_', true) . '.' . $extension;
    }
}
