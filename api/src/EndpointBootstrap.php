<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use Throwable;

final class EndpointBootstrap
{
    private const UNAVAILABLE = 'Le service est temporairement indisponible. Veuillez réessayer plus tard.';

    /** @var Closure(array<string, mixed>): callable(array): void */
    private Closure $mailerFactory;

    /** @param null|callable(array<string, mixed>): callable(array): void $mailerFactory */
    public function __construct(
        private readonly string $configPath,
        private readonly string $rateLimitDirectory,
        ?callable $mailerFactory = null,
    ) {
        $this->mailerFactory = $mailerFactory instanceof Closure
            ? $mailerFactory
            : Closure::fromCallable($mailerFactory ?? static fn (array $config): callable => [new SmtpMailer($config), 'send']);
    }

    /** @param array<string, mixed> $server @param array<string, mixed> $post @param array<string, mixed> $files @return array{status: int, headers: array<string, string>, body: string} */
    public function handle(array $server, array $post, array $files): array
    {
        try {
            $config = $this->loadConfig();
            $secret = $config['rate_limit_secret'] ?? null;
            if (!is_string($secret) || strlen($secret) < 32) {
                throw new InvalidArgumentException('Invalid private configuration.');
            }
            $mailer = ($this->mailerFactory)($config);
            if (!is_callable($mailer)) {
                throw new InvalidArgumentException('Invalid mailer factory.');
            }
            $limiter = new RateLimiter(
                $this->rateLimitDirectory,
                $this->rateLimitValue($config, 'rate_limit_count'),
                $this->rateLimitValue($config, 'rate_limit_window_seconds'),
                null,
                $secret,
            );
            return (new FormController(new FormValidator(), new MessageFactory(), $limiter, $mailer, $config))->handle($server, $post, $files);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
            return $this->unavailable($server);
        }
    }

    /** @return array<string, mixed> */
    private function loadConfig(): array
    {
        if (!is_file($this->configPath)) {
            throw new InvalidArgumentException('Private configuration is unavailable.');
        }
        $config = require $this->configPath;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Invalid private configuration.');
        }
        return $config;
    }

    /** @param array<string, mixed> $config */
    private function rateLimitValue(array $config, string $key): int
    {
        $value = $config[$key] ?? null;
        if (!is_int($value) || $value <= 0) {
            throw new InvalidArgumentException('Invalid private configuration.');
        }
        return $value;
    }

    /** @param array<string, mixed> $server @return array{status: int, headers: array<string, string>, body: string} */
    private function unavailable(array $server): array
    {
        $json = is_string($server['HTTP_ACCEPT'] ?? null) && str_contains(strtolower($server['HTTP_ACCEPT']), 'application/json');
        if ($json) {
            return [
                'status' => 500,
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
                'body' => json_encode(['ok' => false, 'message' => self::UNAVAILABLE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }
        return [
            'status' => 500,
            'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
            'body' => '<!doctype html><html lang="fr"><meta charset="utf-8"><p>' . self::UNAVAILABLE . '</p></html>',
        ];
    }

    private function logFailure(Throwable $exception): void
    {
        try {
            $reference = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $reference = 'unavailable';
        }
        error_log(sprintf('Form bootstrap failure [%s] %s', $reference, $exception::class));
    }
}
