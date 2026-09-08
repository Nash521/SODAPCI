<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\RateLimiter;

/** @param callable(string):void $callback */
function withRateLimiterDirectory(callable $callback): void
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-rate-' . bin2hex(random_bytes(8));
    try {
        $callback($directory);
    } finally {
        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($directory);
        }
    }
}

test('accepts the first five requests and blocks the sixth', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 1_700_000_000;
        $limiter = new RateLimiter($directory, 5, 60, static fn (): int => $now);

        for ($request = 1; $request <= 5; $request++) {
            assertSame(true, $limiter->consume('192.0.2.10'));
        }
        assertSame(false, $limiter->consume('192.0.2.10'));
    });
});

test('keeps each IP on an independent counter', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);

        assertSame(true, $limiter->consume('192.0.2.10'));
        assertSame(false, $limiter->consume('192.0.2.10'));
        assertSame(true, $limiter->consume('192.0.2.11'));
    });
});

test('expires timestamps older than the sliding window', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 100;
        $limiter = new RateLimiter($directory, 2, 10, static fn (): int => $now);

        assertSame(true, $limiter->consume('198.51.100.4'));
        $now = 101;
        assertSame(true, $limiter->consume('198.51.100.4'));
        $now = 111;
        assertSame(true, $limiter->consume('198.51.100.4'));
    });
});

test('does not extend the window when a request is rejected', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 100;
        $limiter = new RateLimiter($directory, 1, 10, static fn (): int => $now);

        assertSame(true, $limiter->consume('203.0.113.8'));
        $now = 109;
        assertSame(false, $limiter->consume('203.0.113.8'));
        $now = 111;
        assertSame(true, $limiter->consume('203.0.113.8'));
    });
});

test('canonicalizes IPv6 and hashes unusual input into safe filenames', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);

        assertSame(true, $limiter->consume(' 2001:0DB8:0:0:0:0:0:1 '));
        assertSame(false, $limiter->consume('2001:db8::1'));
        assertSame(true, $limiter->consume('../../etc/passwd'));

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            assertTrue((bool) preg_match('/^[a-f0-9]{64}\.(?:lock|state)$/', $entry));
            assertTrue(!str_contains($entry, '2001:'));
            assertTrue(!str_contains($entry, 'passwd'));
        }
    });
});

test('creates a private state directory and never stores a raw IP', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $ip = '198.51.100.77';
        $limiter = new RateLimiter($directory, 2, 60, static fn (): int => 1_700_000_000);
        assertTrue(is_dir($directory));
        assertSame(true, $limiter->consume($ip));

        $state = '';
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [] as $path) {
            $state .= (string) file_get_contents($path);
        }
        assertTrue(!str_contains($state, $ip));
        assertTrue((bool) preg_match('/\A(?:\d+\n?)*\z/', $state));
        if (DIRECTORY_SEPARATOR !== '\\') {
            assertSame(0700, fileperms($directory) & 0777);
        }
    });
});

test('fails closed with a controlled exception when state is corrupt', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 2, 60, static fn (): int => 1_700_000_000);
        assertSame(true, $limiter->consume('192.0.2.55'));
        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        file_put_contents($stateFiles[0], "not-a-timestamp\n");

        assertThrows(RuntimeException::class, 'Rate limiter state is corrupt.', fn (): bool => $limiter->consume('192.0.2.55'));
    });
});

test('rejects invalid constructor arguments', function (): void {
    assertThrows(InvalidArgumentException::class, 'Rate limit must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 0, 60));
    assertThrows(InvalidArgumentException::class, 'Rate limit must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), -1, 60));
    assertThrows(InvalidArgumentException::class, 'Window duration must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 1, 0));
    assertThrows(InvalidArgumentException::class, 'Window duration must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 1, -1));
    assertThrows(InvalidArgumentException::class, 'Rate limiter directory must not be empty.', fn (): RateLimiter => new RateLimiter('', 1, 60));
});

test('two limiter instances share locked state for the same IP', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $clock = static fn (): int => 1_700_000_000;
        $first = new RateLimiter($directory, 1, 60, $clock);
        $second = new RateLimiter($directory, 1, 60, $clock);

        assertSame(true, $first->consume('203.0.113.25'));
        assertSame(false, $second->consume('203.0.113.25'));
    });
});
