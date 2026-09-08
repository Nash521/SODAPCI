<?php
declare(strict_types=1);

use Sodapci\Forms\EndpointBootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

$response = (new EndpointBootstrap(
    dirname(__DIR__, 2) . '/sodap-mail-config.php',
    dirname(__DIR__) . '/var/rate-limits',
))->handle($_SERVER, $_POST, $_FILES);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
