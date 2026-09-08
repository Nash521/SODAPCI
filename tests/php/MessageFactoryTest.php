<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\MessageFactory;

test('creates the three exact form subjects and a stable message shape', function (): void {
    $factory = new MessageFactory(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-08T12:34:56+00:00'));

    $appointment = $factory->create([
        'form_type' => 'appointment',
        'name' => 'Awa Kone',
        'email' => 'awa@example.com',
        'phone' => '+225 01 02 03 04 05',
        'subject' => 'Partenariat',
        'message' => 'Bonjour',
    ]);
    $contact = $factory->create([
        'form_type' => 'contact',
        'name' => 'Awa Kone',
        'email' => 'awa@example.com',
        'phone' => '+225 01 02 03 04 05',
        'subject' => 'Partenariat',
        'message' => 'Bonjour',
    ]);
    $application = $factory->create([
        'form_type' => 'application',
        'name' => 'Jean Koffi',
        'email' => 'jean@example.com',
        'position' => 'Technicien terrain',
        'attachments' => [],
    ]);

    assertSame('[Site SODAP-CI] Demande de rendez-vous', $appointment['subject']);
    assertSame('[Site SODAP-CI] Contact — Partenariat', $contact['subject']);
    assertSame('[Site SODAP-CI] Candidature — Technicien terrain', $application['subject']);
    assertSame(['subject', 'html', 'text', 'reply_to', 'attachments'], array_keys($contact));
});

test('escapes every HTML value while keeping literal text and readable newlines', function (): void {
    $message = (new MessageFactory(static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-08T12:34:56+00:00')))->create([
        'form_type' => 'contact',
        'name' => '<Awa> "Kone" & amis',
        'email' => 'awa@example.com',
        'phone' => '+225 01 02 03 04 05',
        'subject' => 'R&D <urgent>',
        'message' => "<script>alert('x')</script>\r\nDeuxième ligne & suite",
    ]);

    assertContains('&lt;Awa&gt; &quot;Kone&quot; &amp; amis', $message['html']);
    assertContains('R&amp;D &lt;urgent&gt;', $message['html']);
    assertContains('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;<br>', $message['html']);
    assertTrue(!str_contains($message['html'], '<script>'), 'HTML was not escaped.');
    assertContains("<script>alert('x')</script>\nDeuxième ligne & suite", $message['text']);
});

test('keeps application attachments only in the output attachments field', function (): void {
    $attachments = [
        ['path' => '/tmp/sodapci-cv.pdf', 'name' => 'cv.pdf', 'mime' => 'application/pdf', 'size' => 42],
        ['path' => 'C:\\Windows\\Temp\\letter.docx', 'name' => 'letter.docx', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'size' => 84],
    ];
    $message = (new MessageFactory())->create([
        'form_type' => 'application',
        'name' => 'Jean Koffi',
        'email' => 'jean@example.com',
        'position' => 'Technicien terrain',
        'attachments' => $attachments,
        'password' => 'do-not-include',
        'ip' => '192.0.2.10',
        'tmp_name' => '/tmp/secret',
    ]);

    assertSame($attachments, $message['attachments']);
    assertTrue(!str_contains($message['html'], '/tmp/'));
    assertTrue(!str_contains($message['html'], 'Windows'));
    assertTrue(!str_contains($message['html'], 'do-not-include'));
    assertTrue(!str_contains($message['html'], '192.0.2.10'));
    assertTrue(!str_contains($message['text'], '/tmp/'));
    assertTrue(!str_contains($message['text'], 'Windows'));
    assertTrue(!str_contains($message['text'], 'do-not-include'));
    assertTrue(!str_contains($message['text'], '192.0.2.10'));
});

test('uses validated reply address and an Abidjan timestamp from the injected clock', function (): void {
    $clock = static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-09-08T12:34:56+00:00', new \DateTimeZone('UTC'));
    $message = (new MessageFactory($clock))->create([
        'form_type' => 'appointment',
        'name' => 'Awa Kone',
        'email' => 'awa@example.com',
        'phone' => '+225 01 02 03 04 05',
        'subject' => 'Rendez-vous',
        'message' => 'Bonjour, merci de me recontacter.',
    ]);

    assertSame('awa@example.com', $message['reply_to']);
    assertContains('2026-09-08T12:34:56+00:00', $message['html']);
    assertContains('2026-09-08T12:34:56+00:00', $message['text']);
});

test('rejects an unknown form type', function (): void {
    try {
        (new MessageFactory())->create([
            'form_type' => 'newsletter',
            'name' => 'Awa Kone',
            'email' => 'awa@example.com',
        ]);
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException('Unknown form type was accepted.');
});
