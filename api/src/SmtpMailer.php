<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer
{
    /** @var array{smtp_host: string, smtp_port: int, smtp_encryption: string, smtp_username: string, smtp_password: string, from_email: string, from_name: string, recipient_email: string} */
    private array $config;

    /** @var Closure(): PHPMailer */
    private Closure $factory;

    /** @param array<string, mixed> $config @param null|callable(): PHPMailer $factory */
    public function __construct(array $config, ?callable $factory = null)
    {
        $this->config = $this->validateConfig($config);
        $this->factory = $factory instanceof Closure
            ? $factory
            : Closure::fromCallable($factory ?? static fn (): PHPMailer => new PHPMailer(true));
    }

    /** @param array{subject: string, html: string, text: string, reply_to: string, attachments?: array} $message */
    public function send(array $message): void
    {
        $mailer = ($this->factory)();
        if (!$mailer instanceof PHPMailer) {
            throw new InvalidArgumentException('Invalid SMTP mailer factory.');
        }

        $mailer->isSMTP();
        $mailer->Host = $this->config['smtp_host'];
        $mailer->Port = $this->config['smtp_port'];
        $mailer->SMTPSecure = $this->config['smtp_encryption'] === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->config['smtp_username'];
        $mailer->Password = $this->config['smtp_password'];
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Timeout = 15;
        $mailer->SMTPDebug = 0;
        $mailer->setFrom($this->config['from_email'], $this->config['from_name']);
        $mailer->addAddress($this->config['recipient_email']);
        $mailer->addReplyTo($this->messageValue($message, 'reply_to'));
        $mailer->isHTML(true);
        $mailer->Subject = $this->messageValue($message, 'subject');
        $mailer->Body = $this->messageValue($message, 'html');
        $mailer->AltBody = $this->messageValue($message, 'text');

        foreach ($message['attachments'] ?? [] as $attachment) {
            if (!is_array($attachment)) {
                throw new InvalidArgumentException('Invalid message attachment.');
            }
            $path = $this->attachmentValue($attachment, 'path');
            $name = $this->attachmentValue($attachment, 'name');
            $mime = $this->attachmentValue($attachment, 'mime');
            $mailer->addAttachment($path, $name, PHPMailer::ENCODING_BASE64, $mime);
        }

        $mailer->send();
    }

    /** @param array<string, mixed> $config @return array{smtp_host: string, smtp_port: int, smtp_encryption: string, smtp_username: string, smtp_password: string, from_email: string, from_name: string, recipient_email: string} */
    private function validateConfig(array $config): array
    {
        $host = $this->requiredString($config, 'smtp_host');
        $username = $this->requiredString($config, 'smtp_username');
        $password = $this->requiredString($config, 'smtp_password');
        $fromEmail = $this->email($config, 'from_email');
        $fromName = $this->requiredString($config, 'from_name');
        $recipient = $this->email($config, 'recipient_email');
        $portValue = $config['smtp_port'] ?? null;
        $port = is_int($portValue) ? $portValue : (is_string($portValue) && preg_match('/\A\d+\z/D', $portValue) === 1 ? (int) $portValue : 0);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid SMTP port configuration.');
        }
        $encryption = is_string($config['smtp_encryption'] ?? null) ? strtolower(trim($config['smtp_encryption'])) : '';
        if (!in_array($encryption, ['ssl', 'tls'], true)) {
            throw new InvalidArgumentException('Invalid SMTP encryption configuration.');
        }

        return [
            'smtp_host' => $host,
            'smtp_port' => $port,
            'smtp_encryption' => $encryption,
            'smtp_username' => $username,
            'smtp_password' => $password,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'recipient_email' => $recipient,
        ];
    }

    /** @param array<string, mixed> $config */
    private function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new InvalidArgumentException('Invalid SMTP configuration.');
        }
        return trim($value);
    }

    /** @param array<string, mixed> $config */
    private function email(array $config, string $key): string
    {
        $value = $this->requiredString($config, $key);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid SMTP configuration.');
        }
        return $value;
    }

    /** @param array<string, mixed> $message */
    private function messageValue(array $message, string $key): string
    {
        $value = $message[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid outgoing message.');
        }
        return $value;
    }

    /** @param array<string, mixed> $attachment */
    private function attachmentValue(array $attachment, string $key): string
    {
        $value = $attachment[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid message attachment.');
        }
        return $value;
    }
}
