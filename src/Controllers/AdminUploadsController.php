<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\AuthMiddleware;
use App\Core\Uuid;
use App\Repositories\FileRepository;

final class AdminUploadsController
{
    private const MAX_BYTES = 10_000_000; // 10 MB
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * POST /v1/admin/uploads?kind=brands|variants|misc
     * multipart/form-data with file field `file`
     */
    public function upload(Request $request): void
    {
        $claims = AuthMiddleware::requireAdmin($request);
        if ($claims === null) {
            return;
        }

        $kind = trim((string) ($request->query('kind') ?? 'misc'));
        if ($kind !== 'brands' && $kind !== 'variants' && $kind !== 'misc') {
            Response::json(['error' => 'Invalid kind', 'errors' => ['kind' => 'Use brands, variants, or misc.']], 422);
            return;
        }

        if (!isset($_FILES['file'])) {
            Response::json(['error' => 'Missing file', 'errors' => ['file' => 'Provide multipart field "file".']], 422);
            return;
        }

        $f = $_FILES['file'];
        if (!is_array($f) || !isset($f['tmp_name'], $f['size'], $f['error'])) {
            Response::json(['error' => 'Invalid upload'], 422);
            return;
        }

        if ((int) $f['error'] !== UPLOAD_ERR_OK) {
            Response::json(['error' => 'Upload failed', 'errors' => ['file' => 'Upload error code: ' . (string) $f['error']]], 422);
            return;
        }

        $size = (int) ($f['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            Response::json(['error' => 'Invalid file size', 'errors' => ['file' => 'Max 10MB.']], 422);
            return;
        }

        $tmp = (string) $f['tmp_name'];
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            Response::json(['error' => 'Invalid file'], 422);
            return;
        }

        $mime = $this->detectMime($tmp);
        if ($mime === null || !array_key_exists($mime, self::ALLOWED_MIME)) {
            Response::json(['error' => 'Unsupported file type', 'errors' => ['file' => 'Only images are allowed.']], 422);
            return;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $fileName = $this->uniqueName($ext);

        $storageRoot = __DIR__ . '/../../storage';
        if (!is_dir($storageRoot)) {
            if (!mkdir($storageRoot, 0775, true) && !is_dir($storageRoot)) {
                throw new HttpException('Upload storage unavailable', 500);
            }
        }

        $relativeDir = 'uploads/' . $kind;
        $destDir = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativeDir;
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                throw new HttpException('Could not create upload directory', 500);
            }
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . $fileName;
        if (!move_uploaded_file($tmp, $destPath)) {
            throw new HttpException('Could not save file', 500);
        }

        @chmod($destPath, 0644);

        $id = Uuid::v4();
        $accessKey = bin2hex(random_bytes(32));
        $createdBy = (string) ($claims['sub'] ?? '');
        if ($createdBy === '') $createdBy = null;
        $storagePath = $relativeDir . '/' . $fileName;
        $originalName = isset($f['name']) && is_string($f['name']) ? $f['name'] : null;

        FileRepository::insert(
            $id,
            $createdBy,
            $kind,
            $storagePath,
            $originalName,
            $mime,
            $size,
            $accessKey
        );

        $appUrl = Env::get('APP_URL', '');
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalHost = is_string($host) && ($host === 'localhost' || str_starts_with($host, 'localhost:') || str_starts_with($host, '127.0.0.1'));
        // In local dev, APP_URL may point to production; prefer the request host so previews work.
        $base = ($appUrl !== '' && !$isLocalHost) ? rtrim($appUrl, '/') : $this->inferBaseUrl();
        $url = $base . '/v1/files?id=' . $id . '&key=' . $accessKey;

        Response::json([
            'id' => $id,
            'url' => $url,
            'mime' => $mime,
            'size' => $size,
        ], 201);
    }

    private function uniqueName(string $ext): string
    {
        $rand = bin2hex(random_bytes(10));
        return (string) time() . '-' . $rand . '.' . $ext;
    }

    private function detectMime(string $path): ?string
    {
        if (!function_exists('finfo_open')) {
            return null;
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) return null;
        $mime = finfo_file($finfo, $path);
        // PHP 8.5+: finfo objects are freed automatically.
        if (function_exists('finfo_close')) {
            @finfo_close($finfo);
        }
        return is_string($mime) ? $mime : null;
    }

    private function inferBaseUrl(): string
    {
        $proto = 'https';
        $xfp = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (is_string($xfp) && $xfp !== '') {
            $proto = explode(',', $xfp)[0] ?: $proto;
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $proto = 'https';
        } else {
            $proto = 'http';
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!is_string($host) || $host === '') $host = 'localhost';
        return $proto . '://' . $host;
    }
}

