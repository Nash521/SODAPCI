<?php
declare(strict_types=1);

use Sodapci\Forms\FormValidator;

function validator(): FormValidator
{
    return new FormValidator(static fn (string $path): bool => is_file($path));
}

function validMessagePost(string $type = 'contact'): array
{
    return [
        'form_type' => $type,
        'name' => '  Awa Kone  ',
        'email' => 'awa@example.test',
        'subject' => 'Demande de renseignements',
        'message' => "Bonjour,\r\n\tje souhaite obtenir des renseignements complémentaires.",
        'phone' => '+225 07 01 02 03 04',
        'website' => '',
    ];
}

function validApplicationPost(): array
{
    return [
        'form_type' => 'application',
        'name' => 'Awa Kone',
        'email' => 'awa@example.test',
        'position' => 'Chargee de projet',
        'website' => '',
    ];
}

function temporaryFile(string $contents, string $name, ?int $size = null, int $error = UPLOAD_ERR_OK): array
{
    $path = tempnam(sys_get_temp_dir(), 'sodapci-test-');
    if ($path === false) {
        throw new RuntimeException('Impossible de créer le fichier temporaire.');
    }
    file_put_contents($path, $contents);
    if ($size !== null) {
        $handle = fopen($path, 'c+b');
        ftruncate($handle, $size);
        fclose($handle);
    }
    trackTemporaryFile($path);

    return ['name' => $name, 'tmp_name' => $path, 'error' => $error, 'size' => filesize($path)];
}

function pdfFile(string $name = 'cv.pdf', ?int $size = null, int $error = UPLOAD_ERR_OK): array
{
    return temporaryFile("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<<>>\n%%EOF\n", $name, $size, $error);
}

function docFile(): array
{
    return temporaryFile("\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 4096), 'lettre.doc');
}

function docxFile(): array
{
    $path = tempnam(sys_get_temp_dir(), 'sodapci-docx-');
    if ($path === false) {
        throw new RuntimeException('Impossible de créer le fichier temporaire.');
    }
    $archive = new ZipArchive();
    assertSame(true, $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $archive->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $archive->close();
    trackTemporaryFile($path);

    return ['name' => 'lettre.docx', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

function applicationFiles(?array $cv = null, ?array $letter = null): array
{
    return ['cv' => $cv ?? pdfFile('cv.pdf'), 'cover_letter' => $letter ?? pdfFile('lettre.pdf')];
}

function assertValidationError(string $message, callable $callback): void
{
    assertThrows(InvalidArgumentException::class, $message, $callback);
}

test('retourne directement les données canoniques d’un rendez-vous valide', function (): void {
    $data = validator()->validate(validMessagePost('appointment'), []);
    assertSame('appointment', $data['form_type']);
    assertSame('Awa Kone', $data['name']);
    assertSame("Bonjour,\r\n\tje souhaite obtenir des renseignements complémentaires.", $data['message']);
});

test('accepte un message de contact et les sauts de ligne de textarea', function (): void {
    $data = validator()->validate(validMessagePost(), []);
    assertSame('awa@example.test', $data['email']);
    assertSame('+225 07 01 02 03 04', $data['phone']);
});

test('accepte des pièces jointes PDF DOC et DOCX réelles pour une candidature', function (): void {
    foreach ([pdfFile(), docFile(), docxFile()] as $document) {
        $data = validator()->validate(validApplicationPost(), applicationFiles($document));
        assertSame(2, count($data['attachments']));
        assertSame(['path', 'name', 'mime', 'size'], array_keys($data['attachments'][0]));
        assertTrue($data['attachments'][0]['size'] > 0);
    }
});

test('refuse le champ piège avant tout autre contrôle', function (): void {
    $post = validMessagePost('inconnu');
    $post['website'] = 'https://spam.example';
    assertValidationError('Soumission refusée.', fn (): array => validator()->validate($post, []));
});

test('refuse un type de formulaire absent ou inconnu', function (): void {
    foreach ([null, 'newsletter'] as $type) {
        $post = validMessagePost();
        if ($type === null) {
            unset($post['form_type']);
        } else {
            $post['form_type'] = $type;
        }
        assertValidationError('Le type de formulaire est invalide.', fn (): array => validator()->validate($post, []));
    }
});

test('refuse chaque champ requis de contact manquant', function (): void {
    foreach ([
        'name' => 'Veuillez indiquer votre nom.',
        'email' => 'Veuillez indiquer une adresse e-mail valide.',
        'subject' => 'Veuillez indiquer le sujet de votre demande.',
        'message' => 'Veuillez rédiger votre message.',
    ] as $field => $message) {
        $post = validMessagePost();
        $post[$field] = '';
        assertValidationError($message, fn (): array => validator()->validate($post, []));
    }
});

test('refuse chaque champ requis de candidature manquant', function (): void {
    foreach ([
        'name' => 'Veuillez indiquer votre nom.',
        'email' => 'Veuillez indiquer une adresse e-mail valide.',
        'position' => 'Veuillez indiquer le poste recherché.',
    ] as $field => $message) {
        $post = validApplicationPost();
        $post[$field] = '';
        assertValidationError($message, fn (): array => validator()->validate($post, applicationFiles()));
    }
    assertValidationError('Veuillez joindre votre CV.', fn (): array => validator()->validate(validApplicationPost(), ['cover_letter' => pdfFile()]));
    assertValidationError('Veuillez joindre votre lettre de motivation.', fn (): array => validator()->validate(validApplicationPost(), ['cv' => pdfFile()]));
});

test('applique les bornes du nom, du sujet et du poste', function (): void {
    $post = validMessagePost();
    $post['name'] = 'A';
    assertValidationError('Votre nom doit contenir entre 2 et 120 caractères.', fn (): array => validator()->validate($post, []));
    $post['name'] = str_repeat('a', 121);
    assertValidationError('Votre nom doit contenir entre 2 et 120 caractères.', fn (): array => validator()->validate($post, []));
    $post = validMessagePost();
    $post['subject'] = 'A';
    assertValidationError('Le sujet doit contenir entre 2 et 160 caractères.', fn (): array => validator()->validate($post, []));
    $post['subject'] = str_repeat('a', 161);
    assertValidationError('Le sujet doit contenir entre 2 et 160 caractères.', fn (): array => validator()->validate($post, []));
    $post = validApplicationPost();
    $post['position'] = 'A';
    assertValidationError('Le poste recherché doit contenir entre 2 et 160 caractères.', fn (): array => validator()->validate($post, applicationFiles()));
    $post['position'] = str_repeat('a', 161);
    assertValidationError('Le poste recherché doit contenir entre 2 et 160 caractères.', fn (): array => validator()->validate($post, applicationFiles()));
});

test('refuse les e-mails invalides et plus longs que 254 caractères', function (): void {
    $post = validMessagePost();
    $post['email'] = "awa@\nexample.test";
    assertValidationError('Veuillez indiquer une adresse e-mail valide.', fn (): array => validator()->validate($post, []));
    $post['email'] = str_repeat('a', 244) . '@example.test';
    assertValidationError('Veuillez indiquer une adresse e-mail valide.', fn (): array => validator()->validate($post, []));
});

test('refuse les messages trop courts, trop longs ou avec un contrôle interdit', function (): void {
    $post = validMessagePost();
    $post['message'] = 'Court';
    assertValidationError('Votre message doit contenir au moins 10 caractères.', fn (): array => validator()->validate($post, []));
    $post['message'] = str_repeat('a', 5001);
    assertValidationError('Votre message ne doit pas dépasser 5000 caractères.', fn (): array => validator()->validate($post, []));
    $post['message'] = "Bonjour\x01 vous";
    assertValidationError('Votre message contient des caractères non autorisés.', fn (): array => validator()->validate($post, []));
});

test('refuse les numéros de téléphone trop longs ou invalides', function (): void {
    $post = validMessagePost();
    $post['phone'] = str_repeat('1', 41);
    assertValidationError('Veuillez indiquer un numéro de téléphone valide.', fn (): array => validator()->validate($post, []));
    $post['phone'] = '07-AB-CD';
    assertValidationError('Veuillez indiquer un numéro de téléphone valide.', fn (): array => validator()->validate($post, []));
});

test('refuse le vérificateur de téléversement par défaut hors requête HTTP', function (): void {
    assertValidationError('Le téléversement de votre CV a échoué.', fn (): array => (new FormValidator())->validate(validApplicationPost(), applicationFiles()));
});

test('refuse les erreurs de téléversement et les fichiers au-delà de 5 Mio', function (): void {
    assertValidationError('Le téléversement de votre CV a échoué.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.pdf', null, UPLOAD_ERR_PARTIAL))));
    assertValidationError('Chaque pièce jointe doit faire entre 1 octet et 5 Mio.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.pdf', 5 * 1024 * 1024 + 1), pdfFile())));
});

test('refuse les extensions incohérentes et les formats interdits', function (): void {
    assertValidationError('Le format de votre CV ne correspond pas à son contenu.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.doc'))));
    $file = temporaryFile("#!/bin/sh\necho nope\n", 'cv.sh');
    assertValidationError('Seuls les fichiers PDF, DOC et DOCX sont acceptés pour votre CV.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles($file)));
});

test('renvoie des noms de fichiers ASCII sûrs avec l’extension détectée', function (): void {
    $data = validator()->validate(validApplicationPost(), applicationFiles(pdfFile('../../<script>alert(1)</script>.pdf'), pdfFile('CV?.pdf')));
    assertSame('script.pdf', $data['attachments'][0]['name']);
    assertSame('CV.pdf', $data['attachments'][1]['name']);
    foreach ($data['attachments'] as $attachment) {
        assertSame(1, preg_match('/^[A-Za-z0-9_-]+\.(pdf|doc|docx)$/', $attachment['name']));
    }
});

test('refuse un fichier sans extension même si son contenu est valide', function (): void {
    assertValidationError('Le format de votre CV ne correspond pas à son contenu.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv-sans-extension'))));
});

test('accepte exactement 10 Mio et refuse un total supérieur', function (): void {
    $atLimit = validator()->validate(validApplicationPost(), applicationFiles(
        pdfFile('cv.pdf', 5 * 1024 * 1024),
        pdfFile('lettre.pdf', 5 * 1024 * 1024)
    ));
    assertSame(2, count($atLimit['attachments']));
    assertValidationError('La taille totale des pièces jointes ne doit pas dépasser 10 Mio.', fn (): array => validator()->validate(validApplicationPost(), applicationFiles(
        pdfFile('cv.pdf', 5 * 1024 * 1024),
        pdfFile('lettre.pdf', 5 * 1024 * 1024 + 1)
    )));
});
