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
        $limiter = new RateLimiter($directory, 2, 10, static function () use (&$now): int {
            return $now;
        });

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
        $limiter = new RateLimiter($directory, 1, 10, static function () use (&$now): int {
            return $now;
        });

        assertSame(true, $limiter->consume('203.0.113.8'));
        $now = 109;
        assertSame(false, $limiter->consume('203.0.113.8'));
        $now = 111;
        assertSame(true, $limiter->consume('203.0.113.8'));
    });
});

test('keeps a timestamp exactly at the window boundary active', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 100;
        $limiter = new RateLimiter($directory, 1, 10, static function () use (&$now): int {
            return $now;
        });

        assertSame(true, $limiter->consume('198.51.100.21'));
        $now = 110;
        assertSame(false, $limiter->consume('198.51.100.21'));
    });
});

test('uses the current time when no clock is injected', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $before = time();
        $limiter = new RateLimiter($directory, 1, 60);
        assertSame(true, $limiter->consume('198.51.100.22'));
        $after = time();

        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        $timestamp = (int) trim((string) file_get_contents($stateFiles[0]));
        assertTrue($timestamp >= $before && $timestamp <= $after, 'Default clock timestamp was outside the current time range.');
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

test('a second process waits while the same IP lock is held', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $ip = '203.0.113.25';
        $key = hash('sha256', $ip);
        $lockPath = $directory . DIRECTORY_SEPARATOR . $key . '.lock';
        $lock = fopen($lockPath, 'c+b');
        assertTrue($lock !== false, 'Could not open the contention lock.');
        assertTrue(flock($lock, LOCK_EX), 'Could not acquire the contention lock.');

        $script = tempnam(sys_get_temp_dir(), 'sodapci-rate-child-');
        assertTrue($script !== false, 'Could not create the child process script.');
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $childCode = <<<'PHP'
<?php
declare(strict_types=1);
require %s;
$limiter = new \Sodapci\Forms\RateLimiter((string) $argv[1], 1, 60, static fn (): int => 1700000000);
fwrite(STDOUT, 'ready');
fflush(STDOUT);
usleep(200000);
echo $limiter->consume((string) $argv[2]) ? '1' : '0';
PHP;
        file_put_contents($script, sprintf(
            $childCode,
            var_export($autoload, true),
        ));

        $process = null;
        $pipes = [];
        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg($ip);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assertTrue(is_resource($process), 'Could not start the child process.');
            stream_set_blocking($pipes[1], false);

            $output = '';
            $readyDeadline = microtime(true) + 2.0;
            do {
                $output .= (string) stream_get_contents($pipes[1]);
                if (str_contains($output, 'ready')) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $readyDeadline);
            assertTrue(str_contains($output, 'ready'), 'The child process did not start.');
            usleep(300_000);
            $status = proc_get_status($process);
            assertSame(true, $status['running'], 'The child process entered consume while the lock was held.');

            assertTrue(flock($lock, LOCK_UN), 'Could not release the contention lock.');
            fclose($lock);
            $lock = false;

            $deadline = microtime(true) + 5.0;
            do {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                $output .= (string) stream_get_contents($pipes[1]);
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $output .= (string) stream_get_contents($pipes[1]);
            assertSame(false, $status['running'], 'The child process did not finish after the lock was released.');
            assertSame('ready1', trim($output));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            unlink($script);
        }
    });
});
