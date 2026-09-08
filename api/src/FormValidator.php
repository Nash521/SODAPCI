<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use finfo;
use ZipArchive;

final class FormValidator
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const MAX_TOTAL_FILE_SIZE = 10 * 1024 * 1024;

    /** @var Closure(string): bool */
    private Closure $uploadVerifier;

    /**
     * @param null|callable(string): bool $uploadVerifier
     */
    public function __construct(?callable $uploadVerifier = null)
    {
        $this->uploadVerifier = $uploadVerifier instanceof Closure
            ? $uploadVerifier
            : Closure::fromCallable($uploadVerifier ?? 'is_uploaded_file');
    }

    /**
     * @return array{valid: bool, errors: array<string, string>, data: array<string, mixed>}
     */
    public function validate(array $post, array $files): array
    {
        $errors = [];
        $data = [];
        $formType = $this->text($post['form_type'] ?? null);

        if (!in_array($formType, ['appointment', 'contact', 'application'], true)) {
            $errors['form_type'] = 'Le type de formulaire est invalide.';
            return $this->result($errors, $data);
        }
        $data['form_type'] = $formType;

        if ($this->text($post['website'] ?? '') !== '') {
            $errors['website'] = 'Votre envoi n’a pas pu être traité.';
        }

        $this->validateRequiredText($post, $data, $errors, 'name', 'Veuillez indiquer votre nom.', 120);
        $this->validateEmail($post, $data, $errors);

        if ($formType === 'application') {
            $this->validateRequiredText($post, $data, $errors, 'position', 'Veuillez indiquer le poste recherché.', 160);
            $attachments = $this->validateAttachments($files, $errors);
            if ($attachments !== []) {
                $data['attachments'] = $attachments;
            }
        } else {
            $this->validateRequiredText($post, $data, $errors, 'subject', 'Veuillez indiquer le sujet de votre demande.', 200);
            $this->validateMessage($post, $data, $errors);
            $this->validatePhone($post, $data, $errors);
        }

        return $this->result($errors, $data);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $data */
    private function validateRequiredText(array $post, array &$data, array &$errors, string $field, string $requiredMessage, int $maxLength): void
    {
        $value = $this->text($post[$field] ?? null);
        if ($value === '') {
            $errors[$field] = $requiredMessage;
            return;
        }
        if ($this->hasControlCharacter($value) || $this->length($value) > $maxLength) {
            $errors[$field] = 'La valeur indiquée est invalide.';
            return;
        }
        $data[$field] = $value;
    }

    /** @param array<string, string> $errors @param array<string, mixed> $data */
    private function validateEmail(array $post, array &$data, array &$errors): void
    {
        $email = $this->text($post['email'] ?? null);
        if (
            $email === ''
            || $this->hasControlCharacter($email)
            || $this->length($email) > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors['email'] = 'Veuillez indiquer une adresse e-mail valide.';
            return;
        }
        $data['email'] = $email;
    }

    /** @param array<string, string> $errors @param array<string, mixed> $data */
    private function validateMessage(array $post, array &$data, array &$errors): void
    {
        $message = $this->text($post['message'] ?? null);
        if ($message === '') {
            $errors['message'] = 'Veuillez rédiger votre message.';
            return;
        }
        if ($this->hasControlCharacter($message)) {
            $errors['message'] = 'Votre message contient des caractères non autorisés.';
            return;
        }
        $length = $this->length($message);
        if ($length < 10) {
            $errors['message'] = 'Votre message doit contenir au moins 10 caractères.';
            return;
        }
        if ($length > 5000) {
            $errors['message'] = 'Votre message ne doit pas dépasser 5000 caractères.';
            return;
        }
        $data['message'] = $message;
    }

    /** @param array<string, string> $errors @param array<string, mixed> $data */
    private function validatePhone(array $post, array &$data, array &$errors): void
    {
        $phone = $this->text($post['phone'] ?? '');
        if ($phone === '') {
            return;
        }
        if ($this->hasControlCharacter($phone) || $this->length($phone) > 40 || preg_match('/^[0-9+().\-\s]+$/', $phone) !== 1) {
            $errors['phone'] = 'Veuillez indiquer un numéro de téléphone valide.';
            return;
        }
        $data['phone'] = $phone;
    }

    /**
     * @param array<string, mixed> $files
     * @param array<string, string> $errors
     * @return list<array{path: string, name: string, mime: string, size: int}>
     */
    private function validateAttachments(array $files, array &$errors): array
    {
        $attachmentSpecs = [
            'cv' => ['label' => 'CV', 'required' => 'Veuillez joindre votre CV.'],
            'cover_letter' => ['label' => 'lettre de motivation', 'required' => 'Veuillez joindre votre lettre de motivation.'],
        ];
        $candidateSizes = [];
        foreach (array_keys($attachmentSpecs) as $field) {
            $file = $files[$field] ?? null;
            if (
                is_array($file)
                && ($file['error'] ?? null) === UPLOAD_ERR_OK
                && is_string($file['tmp_name'] ?? null)
                && $this->isVerifiedUpload($file['tmp_name'])
            ) {
                $size = filesize($file['tmp_name']);
                if ($size !== false) {
                    $candidateSizes[] = $size;
                }
            }
        }
        if (count($candidateSizes) === 2 && array_sum($candidateSizes) > self::MAX_TOTAL_FILE_SIZE) {
            $errors['attachments'] = 'La taille totale des pièces jointes ne doit pas dépasser 10 Mio.';
            return [];
        }
        $attachments = [];

        foreach ($attachmentSpecs as $field => $spec) {
            $file = $files[$field] ?? null;
            if (!is_array($file) || !isset($file['tmp_name'], $file['name'], $file['error'])) {
                $errors[$field] = $spec['required'];
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[$field] = sprintf('Le téléversement de votre %s a échoué.', $spec['label']);
                continue;
            }
            if (!is_string($file['tmp_name']) || !is_string($file['name']) || !$this->isVerifiedUpload($file['tmp_name'])) {
                $errors[$field] = sprintf('Le téléversement de votre %s a échoué.', $spec['label']);
                continue;
            }
            $size = filesize($file['tmp_name']);
            if ($size === false || $size < 1 || $size > self::MAX_FILE_SIZE) {
                $errors[$field] = 'Chaque pièce jointe doit faire entre 1 octet et 5 Mio.';
                continue;
            }

            $name = $this->sanitizeFilename($file['name']);
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = $this->detectMime($file['tmp_name']);
            if (!in_array($extension, ['pdf', 'doc', 'docx'], true) || !$this->mimeMatchesExtension($mime, $extension, $file['tmp_name'])) {
                $errors[$field] = in_array($extension, ['pdf', 'doc', 'docx'], true)
                    ? sprintf('Le format de votre %s ne correspond pas à son contenu.', $spec['label'])
                    : sprintf('Seuls les fichiers PDF, DOC et DOCX sont acceptés pour votre %s.', $spec['label']);
                continue;
            }
            $attachments[] = ['path' => $file['tmp_name'], 'name' => $name, 'mime' => $mime, 'size' => $size];
        }

        return count($errors) === 0 ? $attachments : [];
    }

    private function isVerifiedUpload(string $path): bool
    {
        return ($this->uploadVerifier)($path);
    }

    private function detectMime(string $path): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    }

    private function mimeMatchesExtension(string $mime, string $extension, string $path): bool
    {
        return match ($extension) {
            'pdf' => $mime === 'application/pdf',
            'doc' => in_array($mime, ['application/msword', 'application/x-ole-storage', 'application/CDFV2'], true),
            'docx' => $mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                || (in_array($mime, ['application/zip', 'application/x-zip-compressed'], true) && $this->isWordDocumentPackage($path)),
            default => false,
        };
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

        return $hasDocument && is_string($contentTypes)
            && str_contains($contentTypes, 'wordprocessingml.document');
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        return $name === '' ? 'document' : $name;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function hasControlCharacter(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    /** @param array<string, string> $errors @param array<string, mixed> $data */
    private function result(array $errors, array $data): array
    {
        return ['valid' => $errors === [], 'errors' => $errors, 'data' => $data];
    }
}
