<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Middleware\AuthMiddleware;
use App\Repositories\FileRepository;

final class FilesController
{
    /**
     * GET /v1/files?id=<uuid>&key=<access_key>
     *
     * Access rules:
     * - If valid admin Bearer token → allow (no key required)
     * - Else require `key` to match file.access_key
     */
    public function serve(Request $request): void
    {
        $id = trim((string) ($request->query('id') ?? ''));
        if ($id === '' || !Uuid::isValid($id)) {
            Response::json(['error' => 'Invalid id', 'errors' => ['id' => 'Must be a valid UUID']], 422);
            return;
        }

        $file = FileRepository::findActiveById($id);
        if ($file === null) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        // Access rules:
        // - If Authorization header is present, require admin (and return early on failure).
        // - If no Authorization header, allow access via per-file key.
        $authHeader = $request->header('Authorization');
        if ($authHeader !== null && trim($authHeader) !== '') {
            if (AuthMiddleware::requireAdmin($request) === null) {
                return; // middleware already responded 401/403
            }
        } else {
            $key = trim((string) ($request->query('key') ?? ''));
            if ($key === '' || !hash_equals((string) $file['access_key'], $key)) {
                Response::json(['error' => 'Forbidden'], 403);
                return;
            }
        }

        $storageRoot = realpath(__DIR__ . '/../../storage');
        if ($storageRoot === false) {
            throw new HttpException('Storage unavailable', 500);
        }

        $rel = ltrim((string) $file['storage_path'], '/');
        $abs = $storageRoot . DIRECTORY_SEPARATOR . $rel;

        // Ensure path stays within storage root.
        $absReal = realpath($abs);
        if ($absReal === false || !str_starts_with($absReal, $storageRoot . DIRECTORY_SEPARATOR)) {
            Response::json(['error' => 'Not Found'], 404);
            return;
        }

        $download = trim((string) ($request->query('download') ?? ''));
        $asAttachment = $download === '1' || strtolower($download) === 'true';
        Response::file($absReal, (string) $file['mime'], (string) ($file['original_name'] ?? ''), $asAttachment);
    }
}
