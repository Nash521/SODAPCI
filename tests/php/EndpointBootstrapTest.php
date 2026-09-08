<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\EndpointBootstrap;

function removeBootstrapDirectory(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($directory)) {
        rmdir($directory);
    }
}

/** @param mixed $config */
function bootstrapConfigFile(mixed $config): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-mail-config-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($path, "<?php\nreturn " . var_export($config, true) . ";\n");
    trackTemporaryFile($path);
    return $path;
}

test('returns a generic 500 when private configuration is absent or malformed', function (): void {
    $missingSecret = 'super-secret-that-must-never-appear-in-a-response';
    $missing = new EndpointBootstrap(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'not-present-' . $missingSecret . '.php', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-bootstrap-missing');
    $missingResponse = $missing->handle(['HTTP_ACCEPT' => 'application/json'], [], []);
    assertSame(500, $missingResponse['status']);
    assertContains('Le service est temporairement indisponible. Veuillez réessayer plus tard.', $missingResponse['body']);
    assertTrue(!str_contains($missingResponse['body'], $missingSecret));

    $configPath = bootstrapConfigFile(['smtp_password' => $missingSecret]);
    $malformed = new EndpointBootstrap($configPath, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-bootstrap-malformed-' . bin2hex(random_bytes(8)));
    $malformedResponse = $malformed->handle(['HTTP_ACCEPT' => 'application/json'], [], []);
    assertSame(500, $malformedResponse['status']);
    assertTrue(!str_contains($malformedResponse['body'], $missingSecret));
    assertTrue(!str_contains($malformedResponse['body'], $configPath));
});

test('assembles a limiter with the external secret and no generated key file', function (): void {
    $secret = str_repeat('s', 32);
    $configPath = bootstrapConfigFile(array_merge(smtpConfig(), [
        'allowed_hosts' => ['lasodapci.com'],
        'rate_limit_count' => 5,
        'rate_limit_window_seconds' => 3600,
        'rate_limit_secret' => $secret,
    ]));
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-bootstrap-' . bin2hex(random_bytes(8));
    $sent = [];
    $bootstrap = new EndpointBootstrap(
        $configPath,
        $directory,
        static function (array $config) use (&$sent): callable {
            return static function (array $message) use (&$sent): void {
                $sent[] = $message;
            };
        },
    );

    try {
        $response = $bootstrap->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.30'], validContactPost(), []);
        assertSame(200, $response['status']);
        assertSame(1, count($sent));
        assertTrue(!is_file($directory . DIRECTORY_SEPARATOR . '.key'));
    } finally {
        removeBootstrapDirectory($directory);
    }
});
