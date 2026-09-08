<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RateLimiter
{
    private const MAX_BUCKETS = 1000;
    private const KEY_BYTES = 32;
    private const MAINTENANCE_LOCK = '.maintenance.lock';
    private const KEY_FILE = '.key';
    private const TRANSACTION_SUFFIX = '.txn';

    /** @var Closure(): int */
    private Closure $clock;

    /** @param null|callable(): int $clock */
    public function __construct(
        private readonly string $directory,
        private readonly int $limit,
        private readonly int $windowSeconds,
        ?callable $clock = null,
    ) {
        if (trim($directory) === '') {
            throw new InvalidArgumentException('Rate limiter directory must not be empty.');
        }
        if ($limit <= 0) {
            throw new InvalidArgumentException('Rate limit must be positive.');
        }
        if ($windowSeconds <= 0) {
            throw new InvalidArgumentException('Window duration must be positive.');
        }

        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): int => time());
        $this->ensureDirectory();
    }

    public function consume(string $ip): bool
    {
        $normalizedIp = $this->normalizeIp($ip);
        $now = $this->now();
        $maintenance = $this->openHandle($this->path(self::MAINTENANCE_LOCK));

        try {
            $this->lock($maintenance, LOCK_EX);
            $key = $this->loadOrCreateKey();
            $bucket = hash_hmac('sha256', $normalizedIp, $key);
            if ($this->countStateFiles() >= self::MAX_BUCKETS) {
                $this->cleanupExpiredBuckets($now);
            }

            $lockPath = $this->path($bucket . '.lock');
            $statePath = $this->path($bucket . '.state');
            $lock = $this->openHandle($lockPath);
            try {
                $this->lock($lock, LOCK_EX);
                $timestamps = $this->readState($statePath);
                $cutoff = $now - $this->windowSeconds;
                $pruned = array_values(array_filter(
                    $timestamps,
                    static fn (int $timestamp): bool => $timestamp >= $cutoff,
                ));

                if ($pruned === [] && $timestamps !== []) {
                    $this->removeStateFiles($statePath);
                }

                if ($pruned === [] && $this->countActiveBuckets($cutoff) >= self::MAX_BUCKETS) {
                    throw new RuntimeException('Rate limiter bucket quota exhausted.');
                }
                if (count($pruned) >= $this->limit) {
                    if ($pruned !== $timestamps) {
                        $this->writeState($statePath, $pruned);
                    }
                    return false;
                }

                $pruned[] = $now;
                $this->writeState($statePath, $pruned);
                return true;
            } finally {
                $this->unlockAndClose($lock);
            }
        } finally {
            $this->unlockAndClose($maintenance);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create rate limiter directory.');
        }
        $this->securePermissions($this->directory, 0700);
    }

    private function normalizeIp(string $ip): string
    {
        $value = trim($ip);
        if ($value === '') {
            throw new InvalidArgumentException('IP address must not be empty.');
        }

        $packed = @inet_pton($value);
        if ($packed === false) {
            throw new InvalidArgumentException('Invalid IP address.');
        }
        $canonical = @inet_ntop($packed);
        if ($canonical === false) {
            throw new InvalidArgumentException('Invalid IP address.');
        }

        $mappedPrefix = str_repeat("\0", 10) . "\xff\xff";
        if (strlen($packed) === 16 && substr($packed, 0, 12) === $mappedPrefix) {
            $canonical = @inet_ntop(substr($packed, 12));
            if ($canonical === false) {
                throw new InvalidArgumentException('Invalid IP address.');
            }
        }

        return strtolower($canonical);
    }

    private function path(string $name): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $name;
    }

    private function now(): int
    {
        $value = ($this->clock)();
        if (!is_int($value)) {
            throw new RuntimeException('Rate limiter clock must return an integer.');
        }
        return $value;
    }

    /** @return resource */
    private function openHandle(string $path)
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rate limiter lock.');
        }
        $this->securePermissions($path, 0600);
        return $handle;
    }

    /** @param resource $handle */
    private function lock($handle, int $operation): void
    {
        if (!@flock($handle, $operation)) {
            throw new RuntimeException('Unable to lock rate limiter state.');
        }
    }

    /** @param resource $handle */
    private function unlockAndClose($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    private function securePermissions(string $path, int $mode): void
    {
        $changed = @chmod($path, $mode);
        if ($this->isWindows()) {
            return;
        }
        $permissions = @fileperms($path);
        if ($changed === false || $permissions === false || ($permissions & 0777) !== $mode) {
            throw new RuntimeException('Unable to enforce rate limiter permissions.');
        }
    }

    /** @return string 32-byte key */
    private function loadOrCreateKey(): string
    {
        $path = $this->path(self::KEY_FILE);
        $backup = $path . '.bak';
        $primary = $this->readBytes($path);
        if ($primary !== null && strlen($primary) === self::KEY_BYTES) {
            $this->securePermissions($path, 0600);
            return $primary;
        }

        $backupBytes = $this->readBytes($backup);
        if ($backupBytes !== null && strlen($backupBytes) === self::KEY_BYTES) {
            $this->recoverBytes($path, $backupBytes);
            return $backupBytes;
        }
        if ($primary !== null || $backupBytes !== null || is_file($path . '.tmp') || is_file($path . '.recover.tmp')) {
            throw new RuntimeException('Rate limiter key is corrupt.');
        }

        try {
            $key = random_bytes(self::KEY_BYTES);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Unable to create rate limiter key.', 0, $exception);
        }
        $this->writeKey($path, $key);
        return $key;
    }

    private function readBytes(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read rate limiter state.');
        }
        return $contents;
    }

    private function writeKey(string $path, string $key): void
    {
        $this->writeTemp($path . '.tmp', $key, 0600);
        $this->installTemp($path . '.tmp', $path, $path . '.bak');
        $this->securePermissions($path, 0600);
    }

    /** @return list<int> */
    private function readState(string $path): array
    {
        if (is_file($path . self::TRANSACTION_SUFFIX) || is_file($path . self::TRANSACTION_SUFFIX . '.tmp')) {
            if (!is_file($path . self::TRANSACTION_SUFFIX)) {
                throw new RuntimeException('Rate limiter state is corrupt.');
            }
            return $this->recoverStateTransaction($path);
        }

        $primary = $this->readBytes($path);
        if ($primary !== null) {
            $parsed = $this->parseState($primary);
            if ($parsed !== null) {
                $this->securePermissions($path, 0600);
                return $parsed;
            }
        }

        $backup = $this->readBytes($path . '.bak');
        if ($primary === null && $backup === null && !is_file($path . '.tmp') && !is_file($path . '.recover.tmp')) {
            return [];
        }
        throw new RuntimeException('Rate limiter state is corrupt.');
    }

    /** @return list<int>|null */
    private function parseState(string $contents): ?array
    {
        if ($contents === '' || trim($contents) === '') {
            return null;
        }
        $timestamps = [];
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $token) {
            if (preg_match('/\A\d+\z/D', $token) !== 1) {
                return null;
            }
            $timestamp = (int) $token;
            $canonical = ltrim($token, '0');
            $canonical = $canonical === '' ? '0' : $canonical;
            if ((string) $timestamp !== $canonical) {
                return null;
            }
            $timestamps[] = $timestamp;
        }
        return $timestamps;
    }

    /** @param list<int> $timestamps */
    private function writeState(string $path, array $timestamps): void
    {
        $contents = $timestamps === [] ? '' : implode("\n", $timestamps) . "\n";
        $oldContents = $this->readBytes($path);
        if ($oldContents !== null && $this->parseState($oldContents) === null) {
            throw new RuntimeException('Rate limiter state is corrupt.');
        }
        $oldHash = $oldContents === null ? '-' : hash('sha256', $oldContents);
        $newHash = hash('sha256', $contents);
        $this->writeTemp($path . '.tmp', $contents, 0600);
        $marker = "old={$oldHash}\nnew={$newHash}\n";
        $this->writeTemp($path . self::TRANSACTION_SUFFIX . '.tmp', $marker, 0600);
        if (!@rename($path . self::TRANSACTION_SUFFIX . '.tmp', $path . self::TRANSACTION_SUFFIX)) {
            @unlink($path . self::TRANSACTION_SUFFIX . '.tmp');
            throw new RuntimeException('Unable to create rate limiter transaction marker.');
        }

        if (is_file($path)) {
            if (is_file($path . '.bak') && !@unlink($path . '.bak')) {
                throw new RuntimeException('Unable to rotate rate limiter state backup.');
            }
            if (!@rename($path, $path . '.bak')) {
                throw new RuntimeException('Unable to rotate rate limiter state backup.');
            }
        }
        if (!@rename($path . '.tmp', $path)) {
            throw new RuntimeException('Unable to replace rate limiter state.');
        }
        $this->securePermissions($path, 0600);
        @unlink($path . self::TRANSACTION_SUFFIX);
    }

    private function writeTemp(string $path, string $contents, int $mode): void
    {
        @unlink($path);
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to write rate limiter state.');
        }
        try {
            $this->securePermissions($path, $mode);
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = @fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to write rate limiter state.');
                }
                $offset += $written;
            }
            if (!@fflush($handle)) {
                throw new RuntimeException('Unable to write rate limiter state.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new RuntimeException('Unable to flush rate limiter state.');
            }
        } catch (\Throwable $exception) {
            @fclose($handle);
            @unlink($path);
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Unable to write rate limiter state.', 0, $exception);
        }
        @fclose($handle);
    }

    private function installTemp(string $temporary, string $path, string $backup): void
    {
        if (is_file($path)) {
            if (is_file($backup) && !@unlink($backup)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to rotate rate limiter state backup.');
            }
            if (!@rename($path, $backup)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to rotate rate limiter state backup.');
            }
        }

        if (!@rename($temporary, $path)) {
            if (!is_file($path) && is_file($backup)) {
                @rename($backup, $path);
            }
            @unlink($temporary);
            throw new RuntimeException('Unable to replace rate limiter state.');
        }
    }

    private function recoverBytes(string $path, string $contents): void
    {
        $temporary = $path . '.recover.tmp';
        $this->writeTemp($temporary, $contents, 0600);
        if (is_file($path) && !@unlink($path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to recover rate limiter state.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to recover rate limiter state.');
        }
        $this->securePermissions($path, 0600);
    }

    /** @return list<int> */
    private function recoverStateTransaction(string $path): array
    {
        $marker = $this->readBytes($path . self::TRANSACTION_SUFFIX);
        if ($marker === null || preg_match('/\Aold=([a-f0-9]{64}|-)\nnew=([a-f0-9]{64})\n\z/D', $marker, $matches) !== 1) {
            throw new RuntimeException('Rate limiter state is corrupt.');
        }
        $oldHash = $matches[1];
        $newHash = $matches[2];
        $primary = $this->readBytes($path);
        $primaryParsed = $primary === null ? null : $this->parseState($primary);

        if ($primaryParsed !== null) {
            $primaryHash = hash('sha256', $primary);
            if ($primaryHash !== $newHash && $primaryHash !== $oldHash) {
                throw new RuntimeException('Rate limiter state is corrupt.');
            }
            @unlink($path . self::TRANSACTION_SUFFIX);
            @unlink($path . '.tmp');
            return $primaryParsed;
        }
        if ($primary !== null) {
            throw new RuntimeException('Rate limiter state is corrupt.');
        }

        $temporary = $this->readBytes($path . '.tmp');
        $temporaryParsed = $temporary === null ? null : $this->parseState($temporary);
        if ($temporaryParsed !== null && hash('sha256', $temporary) === $newHash) {
            $this->recoverBytes($path, $temporary);
            @unlink($path . self::TRANSACTION_SUFFIX);
            @unlink($path . '.tmp');
            return $temporaryParsed;
        }

        $backup = $this->readBytes($path . '.bak');
        $backupParsed = $backup === null ? null : $this->parseState($backup);
        if ($oldHash !== '-' && $backupParsed !== null && hash('sha256', $backup) === $oldHash) {
            $this->recoverBytes($path, $backup);
            @unlink($path . self::TRANSACTION_SUFFIX);
            @unlink($path . '.tmp');
            return $backupParsed;
        }

        throw new RuntimeException('Rate limiter state is corrupt.');
    }

    private function removeStateFiles(string $path): void
    {
        @unlink($path);
        @unlink($path . '.bak');
        @unlink($path . '.tmp');
        @unlink($path . '.recover.tmp');
    }

    private function cleanupExpiredBuckets(int $now): void
    {
        $cutoff = $now - $this->windowSeconds;
        foreach ($this->bucketStatePaths() as $statePath) {
            $name = basename($statePath);
            if (preg_match('/\A([a-f0-9]{64})\.state\z/D', $name, $matches) !== 1) {
                continue;
            }
            $lockPath = $this->path($matches[1] . '.lock');
            $lock = @fopen($lockPath, 'c+b');
            if ($lock === false) {
                continue;
            }
            $removeLock = false;
            try {
                $this->securePermissions($lockPath, 0600);
                if (!@flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                try {
                    $timestamps = $this->readState($statePath);
                    $active = array_filter($timestamps, static fn (int $timestamp): bool => $timestamp >= $cutoff);
                    if ($active === []) {
                        $this->removeStateFiles($statePath);
                        $removeLock = true;
                    }
                } catch (RuntimeException) {
                    // A corrupt bucket is left for the owning consume call to fail closed.
                } finally {
                    @flock($lock, LOCK_UN);
                }
            } finally {
                @flock($lock, LOCK_UN);
                @fclose($lock);
                if ($removeLock) {
                    @unlink($lockPath);
                }
            }
        }
    }

    private function countStateFiles(): int
    {
        return count($this->bucketStatePaths());
    }

    private function countActiveBuckets(int $cutoff): int
    {
        $count = 0;
        foreach ($this->bucketStatePaths() as $statePath) {
            try {
                $timestamps = $this->readState($statePath);
            } catch (RuntimeException) {
                continue;
            }
            if (array_filter($timestamps, static fn (int $timestamp): bool => $timestamp >= $cutoff) !== []) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<string> */
    private function bucketStatePaths(): array
    {
        $paths = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.state') ?: [] as $path) {
            $paths[$path] = true;
        }
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.state.bak') ?: [] as $path) {
            $paths[substr($path, 0, -4)] = true;
        }
        return array_keys($paths);
    }
}
