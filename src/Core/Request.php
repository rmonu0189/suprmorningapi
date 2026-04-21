<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private ?string $cachedInput = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }

        // Normalize hosted setups where the app may be served via /index.php
        // or from a subdirectory (for example /public).
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $scriptDir = is_string($scriptName) ? dirname($scriptName) : '';
        $scriptBase = is_string($scriptName) ? basename($scriptName) : '';

        if ($scriptBase !== '' && str_starts_with($path, '/' . $scriptBase . '/')) {
            $path = substr($path, strlen('/' . $scriptBase));
        } elseif ($scriptDir !== '' && $scriptDir !== '/' && str_starts_with($path, $scriptDir . '/')) {
            $path = substr($path, strlen($scriptDir));
        }

        return rtrim($path, '/') ?: '/';
    }

    public function header(string $name): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$normalized] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        // POST/PUT bodies: PHP often exposes these without the HTTP_ prefix.
        if (strcasecmp($name, 'Content-Type') === 0) {
            $direct = $_SERVER['CONTENT_TYPE'] ?? null;
            return is_string($direct) && $direct !== '' ? $direct : null;
        }
        if (strcasecmp($name, 'Content-Length') === 0) {
            $direct = $_SERVER['CONTENT_LENGTH'] ?? null;
            if (is_string($direct) && $direct !== '') {
                return $direct;
            }
            if (is_int($direct)) {
                return (string) $direct;
            }
        }

        return null;
    }

    public function ip(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $remoteStr = is_string($remote) ? $remote : '0.0.0.0';
        $trustedStr = \App\Core\Env::get('TRUSTED_PROXIES', '');

        if ($trustedStr === '*' || (in_array($remoteStr, explode(',', $trustedStr), true))) {
            $forwarded = $this->header('X-Forwarded-For');
            if (is_string($forwarded) && $forwarded !== '') {
                $parts = explode(',', $forwarded);
                $candidate = trim($parts[0] ?? '');
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $remoteStr;
    }

    /** Raw request body (for webhooks signature verification). */
    public function rawBody(): string
    {
        if ($this->cachedInput === null) {
            $raw = file_get_contents('php://input');
            $this->cachedInput = is_string($raw) ? $raw : '';
        }

        return $this->cachedInput;
    }

    public function json(): array
    {
        $raw = $this->rawBody();
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function query(string $key): ?string
    {
        $value = $_GET[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (string) $value;
        }

        return null;
    }
}
