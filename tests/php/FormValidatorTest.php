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
        'name' => '  Awa Koné  ',
        'email' => 'awa@example.test',
        'subject' => 'Demande de renseignements',
        'message' => 'Bonjour, je souhaite obtenir des renseignements complémentaires.',
        'phone' => '+225 07 01 02 03 04',
        'website' => '',
    ];
}

function validApplicationPost(): array
{
    return [
        'form_type' => 'application',
        'name' => 'Awa Koné',
        'email' => 'awa@example.test',
        'position' => 'Chargée de projet',
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
    register_shutdown_function(static fn (): bool => is_file($path) ? unlink($path) : false);

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
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('L’extension ZIP est nécessaire pour ce test.');
    }
    $path = tempnam(sys_get_temp_dir(), 'sodapci-docx-');
    if ($path === false) {
        throw new RuntimeException('Impossible de créer le fichier temporaire.');
    }
    $archive = new ZipArchive();
    assertSame(true, $archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
    $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $archive->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $archive->close();
    register_shutdown_function(static fn (): bool => is_file($path) ? unlink($path) : false);

    return ['name' => 'lettre.docx', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
}

function applicationFiles(?array $cv = null, ?array $letter = null): array
{
    return ['cv' => $cv ?? pdfFile('cv.pdf'), 'cover_letter' => $letter ?? pdfFile('lettre.pdf')];
}

function assertError(array $result, string $field, string $message): void
{
    assertSame(false, $result['valid']);
    assertSame($message, $result['errors'][$field] ?? null);
}

test('accepte un rendez-vous valide et normalise les données', function (): void {
    $result = validator()->validate(validMessagePost('appointment'), []);
    assertSame(true, $result['valid']);
    assertSame('Awa Koné', $result['data']['name']);
    assertSame('appointment', $result['data']['form_type']);
});

test('accepte un message de contact valide', function (): void {
    $result = validator()->validate(validMessagePost(), []);
    assertSame(true, $result['valid']);
    assertSame('awa@example.test', $result['data']['email']);
});

test('accepte des pièces jointes PDF DOC et DOCX réelles pour une candidature', function (): void {
    $documents = [pdfFile(), docFile(), docxFile()];
    foreach ($documents as $document) {
        $result = validator()->validate(validApplicationPost(), applicationFiles($document));
        assertSame(true, $result['valid'], 'Le document ' . $document['name'] . ' devrait être accepté.');
        assertSame(2, count($result['data']['attachments']));
        assertSame($document['name'], $result['data']['attachments'][0]['name']);
        assertTrue($result['data']['attachments'][0]['size'] > 0);
    }
});

test('refuse un type de formulaire absent ou inconnu', function (): void {
    foreach ([null, 'newsletter'] as $type) {
        $post = validMessagePost();
        if ($type === null) {
            unset($post['form_type']);
        } else {
            $post['form_type'] = $type;
        }
        assertError(validator()->validate($post, []), 'form_type', 'Le type de formulaire est invalide.');
    }
});

test('refuse un champ piège rempli', function (): void {
    $post = validMessagePost();
    $post['website'] = 'https://spam.example';
    assertError(validator()->validate($post, []), 'website', 'Votre envoi n’a pas pu être traité.');
});

test('refuse les noms et adresses e-mail manquants ou invalides', function (): void {
    $post = validMessagePost();
    $post['name'] = '';
    assertError(validator()->validate($post, []), 'name', 'Veuillez indiquer votre nom.');
    $post = validMessagePost();
    $post['email'] = "awa@\nexample.test";
    assertError(validator()->validate($post, []), 'email', 'Veuillez indiquer une adresse e-mail valide.');
});

test('refuse les messages trop courts ou trop longs', function (): void {
    $post = validMessagePost();
    $post['message'] = 'Court';
    assertError(validator()->validate($post, []), 'message', 'Votre message doit contenir au moins 10 caractères.');
    $post['message'] = str_repeat('a', 5001);
    assertError(validator()->validate($post, []), 'message', 'Votre message ne doit pas dépasser 5000 caractères.');
});

test('refuse les numéros de téléphone trop longs ou invalides', function (): void {
    $post = validMessagePost();
    $post['phone'] = str_repeat('1', 41);
    assertError(validator()->validate($post, []), 'phone', 'Veuillez indiquer un numéro de téléphone valide.');
    $post['phone'] = '07-AB-CD';
    assertError(validator()->validate($post, []), 'phone', 'Veuillez indiquer un numéro de téléphone valide.');
});

test('refuse les pièces jointes de candidature manquantes', function (): void {
    assertError(validator()->validate(validApplicationPost(), ['cv' => pdfFile()]), 'cover_letter', 'Veuillez joindre votre lettre de motivation.');
});

test('refuse les erreurs de téléversement', function (): void {
    assertError(validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.pdf', null, UPLOAD_ERR_PARTIAL))), 'cv', 'Le téléversement de votre CV a échoué.');
});

test('refuse les fichiers au-delà de 5 Mio', function (): void {
    assertError(validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.pdf', 5 * 1024 * 1024 + 1))), 'cv', 'Chaque pièce jointe doit faire entre 1 octet et 5 Mio.');
});

test('refuse les extensions qui ne correspondent pas au contenu détecté', function (): void {
    assertError(validator()->validate(validApplicationPost(), applicationFiles(pdfFile('cv.doc'))), 'cv', 'Le format de votre CV ne correspond pas à son contenu.');
});

test('refuse les formats de fichier interdits', function (): void {
    $file = temporaryFile("#!/bin/sh\necho nope\n", 'cv.sh');
    assertError(validator()->validate(validApplicationPost(), applicationFiles($file)), 'cv', 'Seuls les fichiers PDF, DOC et DOCX sont acceptés pour votre CV.');
});

test('nettoie le nom des pièces jointes retourné', function (): void {
    $result = validator()->validate(validApplicationPost(), applicationFiles(pdfFile('../ CV final .pdf ')));
    assertSame(true, $result['valid']);
    assertSame('CV final .pdf', $result['data']['attachments'][0]['name']);
});

test('retourne exactement deux pièces jointes de candidature', function (): void {
    $result = validator()->validate(validApplicationPost(), applicationFiles());
    assertSame(true, $result['valid']);
    assertSame(2, count($result['data']['attachments']));
    foreach ($result['data']['attachments'] as $attachment) {
        assertSame(['path', 'name', 'mime', 'size'], array_keys($attachment));
    }
});

test('refuse un total de pièces jointes supérieur à 10 Mio', function (): void {
    $result = validator()->validate(validApplicationPost(), applicationFiles(
        pdfFile('cv.pdf', 5 * 1024 * 1024),
        pdfFile('lettre.pdf', 5 * 1024 * 1024 + 1)
    ));
    assertError($result, 'attachments', 'La taille totale des pièces jointes ne doit pas dépasser 10 Mio.');
});
