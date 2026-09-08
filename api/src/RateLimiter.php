<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use RuntimeException;

final class RateLimiter
{
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
        $key = hash('sha256', $this->normalizeIp($ip));
        $lockPath = $this->path($key . '.lock');
        $statePath = $this->path($key . '.state');
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Unable to open rate limiter lock.');
        }
        @chmod($lockPath, 0600);

        try {
            if (!@flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate limiter state.');
            }

            $now = $this->now();
            $timestamps = $this->readState($statePath);
            $cutoff = $now - $this->windowSeconds;
            $pruned = array_values(array_filter(
                $timestamps,
                static fn (int $timestamp): bool => $timestamp >= $cutoff,
            ));

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
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Unable to create rate limiter directory.');
        }

        @chmod($this->directory, 0700);
    }

    private function normalizeIp(string $ip): string
    {
        $value = trim($ip);
        $packed = @inet_pton($value);
        if ($packed !== false) {
            $canonical = @inet_ntop($packed);
            if ($canonical !== false) {
                return strtolower($canonical);
            }
        }

        return strtolower($value);
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

    /** @return list<int> */
    private function readState(string $path): array
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rate limiter state.');
        }
        @chmod($path, 0600);

        try {
            if (!@rewind($handle)) {
                throw new RuntimeException('Unable to read rate limiter state.');
            }
            $contents = @stream_get_contents($handle);
            if ($contents === false) {
                throw new RuntimeException('Unable to read rate limiter state.');
            }
            if ($contents === '' || trim($contents) === '') {
                return [];
            }

            $timestamps = [];
            foreach (preg_split('/\R/', trim($contents)) ?: [] as $token) {
                if (preg_match('/\A\d+\z/D', $token) !== 1) {
                    throw new RuntimeException('Rate limiter state is corrupt.');
                }
                $timestamp = (int) $token;
                $canonical = ltrim($token, '0');
                $canonical = $canonical === '' ? '0' : $canonical;
                if ((string) $timestamp !== $canonical) {
                    throw new RuntimeException('Rate limiter state is corrupt.');
                }
                $timestamps[] = $timestamp;
            }

            return $timestamps;
        } finally {
            @fclose($handle);
        }
    }

    /** @param list<int> $timestamps */
    private function writeState(string $path, array $timestamps): void
    {
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open rate limiter state.');
        }
        @chmod($path, 0600);

        try {
            $contents = $timestamps === [] ? '' : implode("\n", $timestamps) . "\n";
            if (!@rewind($handle) || !@ftruncate($handle, 0)) {
                throw new RuntimeException('Unable to write rate limiter state.');
            }

            $length = strlen($contents);
            $offset = 0;
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
        } finally {
            @fclose($handle);
        }
    }
}
