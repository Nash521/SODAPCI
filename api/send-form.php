<?php
declare(strict_types=1);

use Sodapci\Forms\FormController;
use Sodapci\Forms\FormValidator;
use Sodapci\Forms\MessageFactory;
use Sodapci\Forms\RateLimiter;
use Sodapci\Forms\SmtpMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

const SODAPCI_UNAVAILABLE_MESSAGE = 'Le service est temporairement indisponible. Veuillez réessayer plus tard.';

/** @param array<string, mixed> $server */
function sodapciWantsJson(array $server): bool
{
    return is_string($server['HTTP_ACCEPT'] ?? null) && str_contains(strtolower($server['HTTP_ACCEPT']), 'application/json');
}

/** @param array<string, mixed> $server */
function sodapciUnavailable(array $server): never
{
    http_response_code(500);
    if (sodapciWantsJson($server)) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => SODAPCI_UNAVAILABLE_MESSAGE], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><p>' . SODAPCI_UNAVAILABLE_MESSAGE . '</p></html>';
    }
    exit;
}

/** @param array<string, mixed> $config */
function sodapciRateLimitValue(array $config, string $key): int
{
    $value = $config[$key] ?? null;
    if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException('Invalid rate limiter configuration.');
    }
    return $value;
}

try {
    $configPath = dirname(__DIR__, 2) . '/sodap-mail-config.php';
    if (!is_file($configPath)) {
        sodapciUnavailable($_SERVER);
    }
    $config = require $configPath;
    if (!is_array($config)) {
        sodapciUnavailable($_SERVER);
    }
    $secret = $config['rate_limit_secret'] ?? null;
    if (!is_string($secret) || strlen($secret) < 32) {
        sodapciUnavailable($_SERVER);
    }

    $limiter = new RateLimiter(
        dirname(__DIR__) . '/var/rate-limits',
        sodapciRateLimitValue($config, 'rate_limit_count'),
        sodapciRateLimitValue($config, 'rate_limit_window_seconds'),
        null,
        $secret,
    );
    $smtp = new SmtpMailer($config);
    $controller = new FormController(new FormValidator(), new MessageFactory(), $limiter, [$smtp, 'send'], $config);
    $response = $controller->handle($_SERVER, $_POST, $_FILES);
} catch (Throwable) {
    sodapciUnavailable($_SERVER);
}

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
