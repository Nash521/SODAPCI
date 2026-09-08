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

test('canonicalizes IPv6 and IPv4-mapped IPv6 into safe HMAC filenames', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);

        assertSame(true, $limiter->consume(' 2001:0DB8:0:0:0:0:0:1 '));
        assertSame(false, $limiter->consume('2001:db8::1'));
        assertThrows(InvalidArgumentException::class, 'Invalid IP address.', fn (): bool => $limiter->consume('../../etc/passwd'));

        assertSame(true, $limiter->consume('192.0.2.44'));
        assertSame(false, $limiter->consume('::ffff:192.0.2.44'));

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (str_starts_with($entry, '.')) {
                continue;
            }
            assertTrue((bool) preg_match('/^[a-f0-9]{64}\.(?:lock|state)$/', $entry));
            assertTrue(!str_contains($entry, '2001:'));
        }
    });
});

test('uses a persistent random HMAC key instead of plain SHA-256', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $ip = '198.51.100.88';
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);
        assertSame(true, $limiter->consume($ip));

        $keyPath = $directory . DIRECTORY_SEPARATOR . '.key';
        $key = file_get_contents($keyPath);
        assertTrue(is_string($key) && strlen($key) === 32);
        assertTrue(!hash_equals(hash('sha256', $ip), (string) ($key === false ? '' : $key)));
        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        assertTrue(basename($stateFiles[0]) !== hash('sha256', $ip) . '.state');

        $second = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);
        assertSame(false, $second->consume($ip));
        assertSame($key, file_get_contents($keyPath));
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

test('recovers the last complete backup when the primary state is partial or missing', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 100;
        $limiter = new RateLimiter($directory, 2, 60, static function () use (&$now): int {
            return $now;
        });
        assertSame(true, $limiter->consume('192.0.2.61'));
        $now = 101;
        assertSame(true, $limiter->consume('192.0.2.61'));
        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        $statePath = $stateFiles[0];
        $backupPath = $statePath . '.bak';
        assertTrue(is_file($backupPath));
        $backup = (string) file_get_contents($backupPath);
        assertSame("100\n", $backup);

        file_put_contents($statePath, "101\npartial");
        $now = 102;
        assertSame(true, $limiter->consume('192.0.2.61'));
        assertSame("100\n102\n", file_get_contents($statePath));

        unlink($statePath);
        $now = 103;
        assertSame(true, $limiter->consume('192.0.2.61'));
        assertTrue(is_file($statePath));
        assertSame("100\n103\n", file_get_contents($statePath));
    });
});

test('does not treat an interrupted temporary state as a fresh counter', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 100);
        assertSame(true, $limiter->consume('192.0.2.63'));
        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        $statePath = $stateFiles[0];
        unlink($statePath);
        if (is_file($statePath . '.bak')) {
            unlink($statePath . '.bak');
        }
        file_put_contents($statePath . '.tmp', "partial");

        assertThrows(RuntimeException::class, 'Rate limiter state is corrupt.', fn (): bool => $limiter->consume('192.0.2.63'));
    });
});

test('fails closed when a complete backup cannot be restored', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 2, 60, static fn (): int => 100);
        assertSame(true, $limiter->consume('192.0.2.64'));
        $stateFiles = glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: [];
        assertSame(1, count($stateFiles));
        $statePath = $stateFiles[0];
        $backupPath = $statePath . '.bak';
        rename($statePath, $backupPath);
        mkdir($statePath, 0700);

        assertThrows(RuntimeException::class, 'Unable to recover rate limiter state.', fn (): bool => $limiter->consume('192.0.2.64'));
        assertSame("100\n", file_get_contents($backupPath));
        assertTrue(!is_file($statePath . '.recover.tmp'));
    });
});

test('reclaims expired buckets before enforcing the active-bucket quota', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $now = 100;
        $limiter = new RateLimiter($directory, 1, 60, static function () use (&$now): int {
            return $now;
        });
        assertSame(true, $limiter->consume('192.0.2.70'));

        for ($index = 0; $index < 999; $index++) {
            $bucket = str_pad(dechex($index + 1), 64, '0', STR_PAD_LEFT);
            file_put_contents($directory . DIRECTORY_SEPARATOR . $bucket . '.state', "100\n");
            chmod($directory . DIRECTORY_SEPARATOR . $bucket . '.state', 0600);
        }

        assertSame(false, $limiter->consume('192.0.2.71'));
        $now = 161;
        assertSame(true, $limiter->consume('192.0.2.71'));
        assertTrue(count(glob($directory . DIRECTORY_SEPARATOR . '*.state') ?: []) < 10);
    });
});

test('rejects invalid constructor arguments', function (): void {
    assertThrows(InvalidArgumentException::class, 'Rate limit must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 0, 60));
    assertThrows(InvalidArgumentException::class, 'Rate limit must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), -1, 60));
    assertThrows(InvalidArgumentException::class, 'Window duration must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 1, 0));
    assertThrows(InvalidArgumentException::class, 'Window duration must be positive.', fn (): RateLimiter => new RateLimiter(sys_get_temp_dir(), 1, -1));
    assertThrows(InvalidArgumentException::class, 'Rate limiter directory must not be empty.', fn (): RateLimiter => new RateLimiter('', 1, 60));
    withRateLimiterDirectory(function (string $directory): void {
        $limiter = new RateLimiter($directory, 1, 60);
        assertThrows(InvalidArgumentException::class, 'IP address must not be empty.', fn (): bool => $limiter->consume('   '));
        assertThrows(InvalidArgumentException::class, 'Invalid IP address.', fn (): bool => $limiter->consume('not-an-ip'));
    });
});

test('a second process waits while the same IP lock is held', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $ip = '203.0.113.25';
        $limiter = new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);
        assertSame(true, $limiter->consume('203.0.113.24'));
        $keyBytes = file_get_contents($directory . DIRECTORY_SEPARATOR . '.key');
        assertTrue(is_string($keyBytes) && strlen($keyBytes) === 32, 'The persistent HMAC key was not created.');
        $key = hash_hmac('sha256', $ip, $keyBytes);
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
file_put_contents((string) $argv[3], 'ready');
usleep(500000);
echo $limiter->consume((string) $argv[2]) ? '1' : '0';
PHP;
        file_put_contents($script, sprintf(
            $childCode,
            var_export($autoload, true),
        ));
        $readyFile = tempnam(sys_get_temp_dir(), 'sodapci-rate-ready-');
        assertTrue($readyFile !== false, 'Could not create the child readiness marker.');
        unlink($readyFile);

        $process = null;
        $pipes = [];
        try {
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg($ip) . ' ' . escapeshellarg($readyFile);
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assertTrue(is_resource($process), 'Could not start the child process.');

            $readyDeadline = microtime(true) + 2.0;
            do {
                if (is_file($readyFile)) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $readyDeadline);
            assertTrue(is_file($readyFile), 'The child process did not start.');
            usleep(700_000);
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
                usleep(10_000);
            } while (microtime(true) < $deadline);
            assertSame(false, $status['running'], 'The child process did not finish after the lock was released.');
            assertSame('1', trim((string) stream_get_contents($pipes[1])));
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
            if (is_file($readyFile)) {
                unlink($readyFile);
            }
        }
    });
});

test('simultaneous consumers allow exactly one request at limit one', function (): void {
    withRateLimiterDirectory(function (string $directory): void {
        $ip = '203.0.113.26';
        new RateLimiter($directory, 1, 60, static fn (): int => 1_700_000_000);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $childCode = <<<'PHP'
<?php
declare(strict_types=1);
require %s;
$limiter = new \Sodapci\Forms\RateLimiter((string) $argv[1], 1, 60, static fn (): int => 1700000000);
file_put_contents((string) $argv[3], 'ready');
usleep(500000);
echo $limiter->consume((string) $argv[2]) ? '1' : '0';
PHP;
        $script = tempnam(sys_get_temp_dir(), 'sodapci-rate-race-');
        assertTrue($script !== false, 'Could not create the simultaneous-consumer script.');
        file_put_contents($script, sprintf($childCode, var_export($autoload, true)));

        $processes = [];
        $pipesByProcess = [];
        $readyFiles = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $ready = tempnam(sys_get_temp_dir(), 'sodapci-rate-race-ready-');
                assertTrue($ready !== false, 'Could not create a readiness marker.');
                unlink($ready);
                $readyFiles[] = $ready;
                $pipes = [];
                $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($directory) . ' ' . escapeshellarg($ip) . ' ' . escapeshellarg($ready);
                $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                assertTrue(is_resource($process), 'Could not start a simultaneous consumer.');
                $processes[] = $process;
                $pipesByProcess[] = $pipes;
            }

            foreach ($readyFiles as $ready) {
                $deadline = microtime(true) + 2.0;
                while (!is_file($ready) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                assertTrue(is_file($ready), 'A simultaneous consumer did not start.');
            }

            foreach ($processes as $process) {
                $deadline = microtime(true) + 5.0;
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    usleep(10_000);
                } while (microtime(true) < $deadline);
                assertSame(false, $status['running'], 'A simultaneous consumer did not finish.');
            }

            $results = [];
            foreach ($pipesByProcess as $pipes) {
                $results[] = trim((string) stream_get_contents($pipes[1]));
            }
            sort($results);
            assertSame(['0', '1'], $results);
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach ($pipesByProcess as $pipes) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            foreach ($readyFiles as $ready) {
                if (is_file($ready)) {
                    unlink($ready);
                }
            }
            unlink($script);
        }
    });
});
