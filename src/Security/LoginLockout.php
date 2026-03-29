<?php

declare(strict_types=1);

namespace App\Security;

final class LoginLockout
{
    private int $maxFailures;
    private int $windowSeconds;
    private int $lockoutSeconds;

    public function __construct(int $maxFailures = 5, int $windowSeconds = 900, int $lockoutSeconds = 900)
    {
        $this->maxFailures = $maxFailures;
        $this->windowSeconds = $windowSeconds;
        $this->lockoutSeconds = $lockoutSeconds;
    }

    public function isLocked(string $email, string $ip): array
    {
        $state = $this->readState($this->statePath($email, $ip));
        $now = time();
        if (($state['locked_until'] ?? 0) > $now) {
            return ['locked' => true, 'retry_after' => (int) $state['locked_until'] - $now];
        }

        return ['locked' => false, 'retry_after' => 0];
    }

    public function onFailedAttempt(string $email, string $ip): array
    {
        $path = $this->statePath($email, $ip);
        $state = $this->readState($path);
        $now = time();

        if (($state['first_failure_at'] ?? 0) === 0 || ($now - (int) $state['first_failure_at']) > $this->windowSeconds) {
            $state = ['first_failure_at' => $now, 'failures' => 0, 'locked_until' => 0];
        }

        $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        if ($state['failures'] >= $this->maxFailures) {
            $state['locked_until'] = $now + $this->lockoutSeconds;
            $this->writeState($path, $state);
            return ['locked' => true, 'retry_after' => $this->lockoutSeconds];
        }

        $this->writeState($path, $state);
        return ['locked' => false, 'retry_after' => 0];
    }

    public function onSuccessfulLogin(string $email, string $ip): void
    {
        $path = $this->statePath($email, $ip);
        if (is_file($path)) {
            unlink($path);
        }
    }

    private function statePath(string $email, string $ip): string
    {
        $dir = __DIR__ . '/../../storage/lockouts';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $key = 'lockout:' . strtolower(trim($email)) . ':' . $ip;
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return ['first_failure_at' => 0, 'failures' => 0, 'locked_until' => 0];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return ['first_failure_at' => 0, 'failures' => 0, 'locked_until' => 0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['first_failure_at' => 0, 'failures' => 0, 'locked_until' => 0];
        }

        return [
            'first_failure_at' => (int) ($decoded['first_failure_at'] ?? 0),
            'failures' => (int) ($decoded['failures'] ?? 0),
            'locked_until' => (int) ($decoded['locked_until'] ?? 0),
        ];
    }

    private function writeState(string $path, array $state): void
    {
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
