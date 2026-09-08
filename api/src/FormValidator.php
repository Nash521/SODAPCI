<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use InvalidArgumentException;
use finfo;
use ZipArchive;

final class FormValidator
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const MAX_TOTAL_FILE_SIZE = 10 * 1024 * 1024;

    /** @var Closure(string): bool */
    private Closure $uploadVerifier;

    /** @param null|callable(string): bool $uploadVerifier */
    public function __construct(?callable $uploadVerifier = null)
    {
        $this->uploadVerifier = $uploadVerifier instanceof Closure
            ? $uploadVerifier
            : Closure::fromCallable($uploadVerifier ?? 'is_uploaded_file');
    }

    /** @return array<string, mixed> */
    public function validate(array $post, array $files): array
    {
        if ($this->text($post['website'] ?? '') !== '') {
            $this->fail('Soumission refusée.');
        }

        $formType = $this->text($post['form_type'] ?? null);
        if (!in_array($formType, ['appointment', 'contact', 'application'], true)) {
            $this->fail('Le type de formulaire est invalide.');
        }

        $data = [
            'form_type' => $formType,
            'name' => $this->requiredHeader($post, 'name', 'Veuillez indiquer votre nom.', 'Votre nom doit contenir entre 2 et 120 caractères.', 2, 120),
            'email' => $this->email($post),
        ];

        if ($formType === 'application') {
            $data['position'] = $this->requiredHeader($post, 'position', 'Veuillez indiquer le poste recherché.', 'Le poste recherché doit contenir entre 2 et 160 caractères.', 2, 160);
            $data['attachments'] = $this->validateAttachments($files);
            return $data;
        }

        $data['subject'] = $this->requiredHeader($post, 'subject', 'Veuillez indiquer le sujet de votre demande.', 'Le sujet doit contenir entre 2 et 160 caractères.', 2, 160);
        $data['message'] = $this->message($post);
        $phone = $this->phone($post);
        if ($phone !== '') {
            $data['phone'] = $phone;
        }

        return $data;
    }

    private function requiredHeader(array $post, string $field, string $requiredMessage, string $boundsMessage, int $min, int $max): string
    {
        $value = $this->text($post[$field] ?? null);
        if ($value === '') {
            $this->fail($requiredMessage);
        }
        if ($this->hasAnyControlCharacter($value)) {
            $this->fail('La valeur indiquée contient des caractères non autorisés.');
        }
        $length = $this->length($value);
        if ($length < $min || $length > $max) {
            $this->fail($boundsMessage);
        }
        return $value;
    }

    private function email(array $post): string
    {
        $email = $this->text($post['email'] ?? null);
        if (
            $email === ''
            || $this->hasAnyControlCharacter($email)
            || $this->length($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $this->fail('Veuillez indiquer une adresse e-mail valide.');
        }
        return $email;
    }

    private function message(array $post): string
    {
        $message = $this->text($post['message'] ?? null);
        if ($message === '') {
            $this->fail('Veuillez rédiger votre message.');
        }
        if ($this->hasDisallowedTextareaControlCharacter($message)) {
            $this->fail('Votre message contient des caractères non autorisés.');
        }
        $length = $this->length($message);
        if ($length < 10) {
            $this->fail('Votre message doit contenir au moins 10 caractères.');
        }
        if ($length > 5000) {
            $this->fail('Votre message ne doit pas dépasser 5000 caractères.');
        }
        return $message;
    }

    private function phone(array $post): string
    {
        $phone = $this->text($post['phone'] ?? '');
        if ($phone === '') {
            return '';
        }
        if ($this->hasAnyControlCharacter($phone) || $this->length($phone) > 40 || preg_match('/^[0-9+(). \-]+$/', $phone) !== 1) {
            $this->fail('Veuillez indiquer un numéro de téléphone valide.');
        }
        return $phone;
    }

    /** @return list<array{path: string, name: string, mime: string, size: int}> */
    private function validateAttachments(array $files): array
    {
        $specs = [
            'cv' => ['label' => 'CV', 'required' => 'Veuillez joindre votre CV.'],
            'cover_letter' => ['label' => 'lettre de motivation', 'required' => 'Veuillez joindre votre lettre de motivation.'],
        ];
        $prepared = [];

        foreach ($specs as $field => $spec) {
            $file = $files[$field] ?? null;
            if (!is_array($file) || !isset($file['name'], $file['tmp_name'], $file['error'])) {
                $this->fail($spec['required']);
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_string($file['name']) || !is_string($file['tmp_name']) || !$this->isVerifiedUpload($file['tmp_name'])) {
                $this->fail(sprintf('Le téléversement de votre %s a échoué.', $spec['label']));
            }
            $size = filesize($file['tmp_name']);
            $prepared[$field] = ['file' => $file, 'size' => $size, 'spec' => $spec];
        }

        if (array_sum(array_column($prepared, 'size')) > self::MAX_TOTAL_FILE_SIZE) {
            $this->fail('La taille totale des pièces jointes ne doit pas dépasser 10 Mio.');
        }

        $attachments = [];
        foreach ($prepared as $field => $item) {
            /** @var array{name: string, tmp_name: string, error: int} $file */
            $file = $item['file'];
            if ($item['size'] === false || $item['size'] < 1 || $item['size'] > self::MAX_FILE_SIZE) {
                $this->fail('Chaque pièce jointe doit faire entre 1 octet et 5 Mio.');
            }
            $mime = $this->detectMime($file['tmp_name']);
            $extension = $this->detectedExtension($mime, $file['tmp_name']);
            if ($extension === null) {
                $this->fail(sprintf('Seuls les fichiers PDF, DOC et DOCX sont acceptés pour votre %s.', $item['spec']['label']));
            }
            $sourceExtension = strtolower(pathinfo($this->basename($file['name']), PATHINFO_EXTENSION));
            if ($sourceExtension === '' || !$this->sourceExtensionMatches($sourceExtension, $extension)) {
                if (in_array($sourceExtension, ['pdf', 'doc', 'docx'], true)) {
                    $this->fail(sprintf('Le format de votre %s ne correspond pas à son contenu.', $item['spec']['label']));
                }
                if ($sourceExtension === '') {
                    $this->fail(sprintf('Le format de votre %s ne correspond pas à son contenu.', $item['spec']['label']));
                }
                $this->fail(sprintf('Seuls les fichiers PDF, DOC et DOCX sont acceptés pour votre %s.', $item['spec']['label']));
            }
            $attachments[] = [
                'path' => $file['tmp_name'],
                'name' => $this->sanitizeFilename($file['name'], $extension),
                'mime' => $mime,
                'size' => $item['size'],
            ];
        }

        return $attachments;
    }

    private function isVerifiedUpload(string $path): bool
    {
        return ($this->uploadVerifier)($path);
    }

    private function detectMime(string $path): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    }

    private function detectedExtension(string $mime, string $path): ?string
    {
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($mime, ['application/msword', 'application/x-ole-storage', 'application/CDFV2'], true)) {
            return 'doc';
        }
        if (
            $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) && $this->isWordDocumentPackage($path))
        ) {
            return 'docx';
        }
        return null;
    }

    private function sourceExtensionMatches(string $sourceExtension, string $detectedExtension): bool
    {
        $safeAliases = [
            'pdf' => ['pdf'],
            'doc' => ['doc'],
            'docx' => ['docx'],
        ];

        return in_array($sourceExtension, $safeAliases[$detectedExtension] ?? [], true);
    }

    private function isWordDocumentPackage(string $path): bool
    {
        if (!class_exists(ZipArchive::class)) {
            return false;
        }
        $archive = new ZipArchive();
        if ($archive->open($path) !== true) {
            return false;
        }
        $contentTypes = $archive->getFromName('[Content_Types].xml');
        $hasDocument = $archive->locateName('word/document.xml') !== false;
        $archive->close();
        return $hasDocument && is_string($contentTypes) && str_contains($contentTypes, 'wordprocessingml.document');
    }

    private function sanitizeFilename(string $name, string $extension): string
    {
        $base = pathinfo($this->basename($name), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base) ?? '';
        $base = trim($base, '-_');
        $base = substr($base, 0, 80);
        return ($base === '' ? 'document' : $base) . '.' . $extension;
    }

    private function basename(string $name): string
    {
        return basename(str_replace('\\', '/', $name));
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function hasAnyControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    private function hasDisallowedTextareaControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @return never */
    private function fail(string $message): void
    {
        throw new InvalidArgumentException($message);
    }
}
