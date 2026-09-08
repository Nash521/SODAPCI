<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use Throwable;

final class FormController
{
    private const UNAVAILABLE = 'Le service est temporairement indisponible. Veuillez réessayer plus tard.';

    /** @var Closure(array): void */
    private Closure $mailer;

    /** @var list<string> */
    private array $allowedHosts;

    /** @var list<int> */
    private array $localHttpPorts;

    /** @param callable(array): void $mailer @param array<string, mixed> $config */
    public function __construct(
        private readonly FormValidator $validator,
        private readonly MessageFactory $messages,
        private readonly \Sodapci\Forms\RateLimiter $limiter,
        callable $mailer,
        array $config,
    ) {
        $this->mailer = $mailer instanceof Closure ? $mailer : Closure::fromCallable($mailer);
        $hosts = $config['allowed_hosts'] ?? null;
        if (!is_array($hosts) || $hosts === []) {
            throw new InvalidArgumentException('Invalid form controller configuration.');
        }
        $this->allowedHosts = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || ($normalized = $this->normalizeHost($host)) === null) {
                throw new InvalidArgumentException('Invalid form controller configuration.');
            }
            $this->allowedHosts[] = $normalized;
        }
        $this->allowedHosts = array_values(array_unique($this->allowedHosts));
        $ports = $config['local_http_ports'] ?? [80];
        if (!is_array($ports) || $ports === []) {
            throw new InvalidArgumentException('Invalid form controller configuration.');
        }
        $this->localHttpPorts = [];
        foreach ($ports as $port) {
            if (!is_int($port) || $port < 1 || $port > 65535) {
                throw new InvalidArgumentException('Invalid form controller configuration.');
            }
            $this->localHttpPorts[] = $port;
        }
        $this->localHttpPorts = array_values(array_unique($this->localHttpPorts));
    }

    /** @param array<string, mixed> $server @param array<string, mixed> $post @param array<string, mixed> $files @return array{status: int, headers: array<string, string>, body: string} */
    public function handle(array $server, array $post, array $files): array
    {
        $json = $this->wantsJson($server);
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') {
            return $this->response(405, 'Méthode non autorisée.', $json);
        }
        if (!$this->originAllowed($server['HTTP_ORIGIN'] ?? null)) {
            return $this->response(403, 'Origine non autorisée.', $json);
        }

        $ip = $server['REMOTE_ADDR'] ?? null;
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->response(422, 'Adresse réseau invalide.', $json);
        }

        try {
            if (!$this->limiter->consume($ip)) {
                return $this->response(429, 'Trop de demandes ont été envoyées. Veuillez réessayer plus tard.', $json);
            }
            $data = $this->validator->validate($post, $files);
            $message = $this->messages->create($data);
            ($this->mailer)($message);
        } catch (InvalidArgumentException $exception) {
            return $this->response(422, $exception->getMessage(), $json);
        } catch (Throwable $exception) {
            $this->logFailure($exception);
            return $this->response(500, self::UNAVAILABLE, $json);
        }

        if ($json) {
            return $this->response(200, 'Votre demande a bien été envoyée.', true, true);
        }
        $formType = $data['form_type'];
        return [
            'status' => 303,
            'headers' => ['Location' => $this->location($formType, 'success')],
            'body' => '',
        ];
    }

    /** @param array<string, mixed> $server */
    private function wantsJson(array $server): bool
    {
        return is_string($server['HTTP_ACCEPT'] ?? null) && str_contains(strtolower($server['HTTP_ACCEPT']), 'application/json');
    }

    private function originAllowed(mixed $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        if (!is_string($origin) || preg_match('/[\r\n]/', $origin) === 1) {
            return false;
        }
        $parts = parse_url($origin);
        if (
            !is_array($parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (isset($parts['path']) && $parts['path'] !== '/')
            || !isset($parts['scheme'], $parts['host'])
            || !is_string($parts['scheme'])
            || !is_string($parts['host'])
        ) {
            return false;
        }
        $host = $this->normalizeHost($parts['host']);
        if ($host === null || !in_array($host, $this->allowedHosts, true)) {
            return false;
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && !is_int($port)) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return $port === null || $port === 443;
        }
        if ($scheme !== 'http' || !in_array($host, ['localhost', '127.0.0.1'], true)) {
            return false;
        }
        return in_array($port ?? 80, $this->localHttpPorts, true);
    }

    private function normalizeHost(string $host): ?string
    {
        $host = strtolower(rtrim(trim($host), '.'));
        if (
            $host === ''
            || (
                filter_var($host, FILTER_VALIDATE_IP) === false
                && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            )
        ) {
            return null;
        }
        return $host;
    }

    private function location(string $formType, string $status): string
    {
        $locations = [
            'appointment' => '../index.html?form_status=%s#appointment-form',
            'contact' => '../contact.html?form_status=%s#contact-form',
            'application' => '../contact.html?form_status=%s#application-form',
        ];
        return sprintf($locations[$formType], $status);
    }

    /** @return array{status: int, headers: array<string, string>, body: string} */
    private function response(int $status, string $message, bool $json, bool $ok = false): array
    {
        if ($json) {
            return [
                'status' => $status,
                'headers' => ['Content-Type' => 'application/json; charset=UTF-8'],
                'body' => json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ];
        }

        return [
            'status' => $status,
            'headers' => ['Content-Type' => 'text/html; charset=UTF-8'],
            'body' => '<!doctype html><html lang="fr"><meta charset="utf-8"><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></html>',
        ];
    }

    private function logFailure(Throwable $exception): void
    {
        try {
            $reference = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $reference = 'unavailable';
        }
        error_log(sprintf('Form processing failure [%s] %s', $reference, $exception::class));
    }
}
