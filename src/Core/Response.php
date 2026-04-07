<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function file(string $absolutePath, string $mime, string $downloadName = ''): void
    {
        if (!is_file($absolutePath)) {
            self::json(['error' => 'Not Found'], 404);
            return;
        }

        $size = filesize($absolutePath);
        if ($size === false) {
            self::json(['error' => 'Not Found'], 404);
            return;
        }

        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) $size);
        header('X-Content-Type-Options: nosniff');

        // Inline by default (images should render). Optional filename when present.
        if ($downloadName !== '') {
            $safe = str_replace(["\r", "\n", '"'], '', $downloadName);
            header('Content-Disposition: inline; filename="' . $safe . '"');
        } else {
            header('Content-Disposition: inline');
        }

        readfile($absolutePath);
    }
}
