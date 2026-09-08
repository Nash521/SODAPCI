<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\FormController;
use Sodapci\Forms\FormValidator;
use Sodapci\Forms\MessageFactory;
use Sodapci\Forms\RateLimiter;

/** @return array<string, mixed> */
function endpointConfig(): array
{
    return ['allowed_hosts' => ['lasodapci.com', 'www.lasodapci.com']];
}

/** @return array<string, string> */
function validContactPost(array $override = []): array
{
    return array_replace([
        'form_type' => 'contact',
        'name' => 'Awa Kone',
        'email' => 'awa@example.test',
        'subject' => 'Informations',
        'message' => 'Bonjour, je souhaite en savoir davantage.',
    ], $override);
}

/** @return array{0: FormController, 1: \ArrayObject<int, array<string, mixed>>, 2: string} */
function endpointController(?callable $mailer = null, int $limit = 5, ?callable $clock = null): array
{
    $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sodapci-endpoint-' . bin2hex(random_bytes(8));
    $captured = new \ArrayObject();
    $mailer ??= static function (array $message) use (&$captured): void {
        $captured[] = $message;
    };
    $limiter = new RateLimiter($directory, $limit, 3600, $clock, str_repeat('r', 32));
    return [new FormController(new FormValidator(), new MessageFactory(), $limiter, $mailer, endpointConfig()), $captured, $directory];
}

function removeEndpointDirectory(string $directory): void
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

test('rejects a GET request', function (): void {
    [$controller, , $directory] = endpointController();
    try {
        assertSame(405, $controller->handle(['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '192.0.2.1'], [], [])['status']);
    } finally {
        removeEndpointDirectory($directory);
    }
});

test('rejects malformed, foreign and suffix-bypass origins while allowing no Origin header', function (): void {
    [$controller, , $directory] = endpointController();
    try {
        foreach (['not a url', 'https://evil.example', 'https://lasodapci.com.evil.example'] as $origin) {
            assertSame(403, $controller->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => $origin, 'REMOTE_ADDR' => '192.0.2.2'], validContactPost(), [])['status']);
        }
        assertSame(200, $controller->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.3'], validContactPost(), [])['status']);
    } finally {
        removeEndpointDirectory($directory);
    }
});

test('returns controlled invalid, rate-limited and invalid-IP responses', function (): void {
    [$controller, , $directory] = endpointController(null, 1);
    try {
        assertSame(422, $controller->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.4'], validContactPost(['email' => 'invalid']), [])['status']);
        assertSame(422, $controller->handle(['REQUEST_METHOD' => 'POST'], validContactPost(), [])['status']);
        assertSame(422, $controller->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => 'spoofed-value'], validContactPost(), [])['status']);
        assertSame(200, $controller->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.5'], validContactPost(), [])['status']);
        assertSame(429, $controller->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.5'], validContactPost(), [])['status']);
    } finally {
        removeEndpointDirectory($directory);
    }
});

test('returns a generic 500 for limiter storage and mailer failures without leaking secrets', function (): void {
    [$brokenLimiter, , $directory] = endpointController(null, 5, static function (): int { throw new RuntimeException('a-secret-that-must-not-leak'); });
    try {
        $response = $brokenLimiter->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.6'], validContactPost(), []);
        assertSame(500, $response['status']);
        assertContains('Le service est temporairement indisponible. Veuillez réessayer plus tard.', $response['body']);
        assertTrue(!str_contains($response['body'], 'a-secret-that-must-not-leak'));
    } finally {
        removeEndpointDirectory($directory);
    }

    [$brokenMailer, , $directory] = endpointController(static function (): void { throw new RuntimeException('a-secret-that-must-not-leak'); });
    try {
        $response = $brokenMailer->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '192.0.2.7'], validContactPost(), []);
        assertSame(500, $response['status']);
        assertTrue(!str_contains($response['body'], 'a-secret-that-must-not-leak'));
    } finally {
        removeEndpointDirectory($directory);
    }
});

test('delivers a valid JSON contact response and trusted redirects only', function (): void {
    [$controller, $captured, $directory] = endpointController();
    try {
        $json = $controller->handle(['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'text/html, application/json', 'REMOTE_ADDR' => '192.0.2.8'], validContactPost(), []);
        assertSame(200, $json['status']);
        assertSame('application/json; charset=UTF-8', $json['headers']['Content-Type']);
        assertSame(['ok' => true, 'message' => 'Votre demande a bien été envoyée.'], json_decode($json['body'], true, 512, JSON_THROW_ON_ERROR));
        assertSame('[Site SODAP-CI] Contact — Informations', $captured[0]['subject']);

        $redirect = $controller->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.9'], validContactPost(), []);
        assertSame(303, $redirect['status']);
        assertSame('../contact.html?form_status=success#contact-form', $redirect['headers']['Location']);

        $untrusted = $controller->handle(['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '192.0.2.10'], validContactPost(['form_type' => 'https://evil.example']), []);
        assertSame(422, $untrusted['status']);
        assertTrue(!str_contains($untrusted['body'], 'evil.example'));
    } finally {
        removeEndpointDirectory($directory);
    }
});
