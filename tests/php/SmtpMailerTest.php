<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use PHPMailer\PHPMailer\PHPMailer;
use Sodapci\Forms\SmtpMailer;

final class RecordingMailer extends PHPMailer
{
    /** @var list<array{path: string, name: string, encoding: string, mime: string}> */
    public array $attachmentsSeen = [];
    public bool $sent = false;

    public function __construct()
    {
        parent::__construct(true);
    }

    public function addAttachment($path, $name = '', $encoding = self::ENCODING_BASE64, $type = '', $disposition = 'attachment')
    {
        $this->attachmentsSeen[] = ['path' => $path, 'name' => $name, 'encoding' => $encoding, 'mime' => $type];
        return true;
    }

    public function send()
    {
        $this->sent = true;
        return true;
    }
}

/** @return array<string, mixed> */
function smtpConfig(): array
{
    return [
        'smtp_host' => 'smtp.example.test',
        'smtp_port' => '465',
        'smtp_encryption' => 'ssl',
        'smtp_username' => 'mailer@example.test',
        'smtp_password' => 'a-secret-that-must-not-leak',
        'from_email' => 'mailer@example.test',
        'from_name' => 'SODAP-CI',
        'recipient_email' => 'inbox@example.test',
    ];
}

test('maps SMTP configuration, message fields and two base64 attachments without a network call', function (): void {
    $recording = new RecordingMailer();
    $mailer = new SmtpMailer(smtpConfig(), static fn (): RecordingMailer => $recording);

    $mailer->send([
        'subject' => 'Sujet exact',
        'html' => '<p>Bonjour</p>',
        'text' => 'Bonjour',
        'reply_to' => 'awa@example.test',
        'attachments' => [
            ['path' => 'C:\\tmp\\cv.pdf', 'name' => 'cv.pdf', 'mime' => 'application/pdf'],
            ['path' => 'C:\\tmp\\lettre.docx', 'name' => 'lettre.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ],
    ]);

    assertSame('smtp', $recording->Mailer);
    assertSame('smtp.example.test', $recording->Host);
    assertSame(465, $recording->Port);
    assertSame(PHPMailer::ENCRYPTION_SMTPS, $recording->SMTPSecure);
    assertTrue($recording->SMTPAuth);
    assertSame('mailer@example.test', $recording->Username);
    assertSame('a-secret-that-must-not-leak', $recording->Password);
    assertSame(PHPMailer::CHARSET_UTF8, $recording->CharSet);
    assertSame('mailer@example.test', $recording->From);
    assertSame('SODAP-CI', $recording->FromName);
    assertSame([['inbox@example.test', '']], array_values($recording->getToAddresses()));
    assertSame([['awa@example.test', '']], array_values($recording->getReplyToAddresses()));
    assertSame('Sujet exact', $recording->Subject);
    assertSame('<p>Bonjour</p>', $recording->Body);
    assertSame('Bonjour', $recording->AltBody);
    assertSame([
        ['path' => 'C:\\tmp\\cv.pdf', 'name' => 'cv.pdf', 'encoding' => PHPMailer::ENCODING_BASE64, 'mime' => 'application/pdf'],
        ['path' => 'C:\\tmp\\lettre.docx', 'name' => 'lettre.docx', 'encoding' => PHPMailer::ENCODING_BASE64, 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ], $recording->attachmentsSeen);
    assertTrue($recording->sent);
});

test('rejects unsafe SMTP configuration without exposing its password', function (): void {
    $config = smtpConfig();
    $config['smtp_encryption'] = 'plain';

    try {
        new SmtpMailer($config);
    } catch (InvalidArgumentException $exception) {
        assertTrue(!str_contains($exception->getMessage(), 'a-secret-that-must-not-leak'));
        return;
    }

    throw new RuntimeException('Unsafe encryption setting was accepted.');
});
