<?php

declare(strict_types=1);

namespace App\Security;

final class RateLimiter
{
    public function consume(string $bucket, int $maxRequests, int $windowSeconds): array
    {
        $now = time();
        $path = $this->bucketPath($bucket);
        $state = $this->readState($path);

        if ($state['reset_at'] <= $now) {
            $state = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        if ($state['count'] >= $maxRequests) {
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => max(1, $state['reset_at'] - $now),
            ];
        }

        $state['count']++;
        $this->writeState($path, $state);

        return [
            'allowed' => true,
            'remaining' => max(0, $maxRequests - $state['count']),
            'retry_after' => max(1, $state['reset_at'] - $now),
        ];
    }

    public function reset(string $bucket): void
    {
        $path = $this->bucketPath($bucket);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function bucketPath(string $bucket): string
    {
        $dir = __DIR__ . '/../../storage/ratelimits';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . hash('sha256', $bucket) . '.json';
    }

    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return ['count' => 0, 'reset_at' => 0];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['count' => 0, 'reset_at' => 0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['count' => 0, 'reset_at' => 0];
        }

        return [
            'count' => (int) ($decoded['count'] ?? 0),
            'reset_at' => (int) ($decoded['reset_at'] ?? 0),
        ];
    }

    private function writeState(string $path, array $state): void
    {
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
