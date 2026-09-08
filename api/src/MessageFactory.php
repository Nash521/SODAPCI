<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

final class MessageFactory
{
    private const TIMEZONE = 'Africa/Abidjan';

    /** @var Closure(): DateTimeInterface|string */
    private Closure $clock;

    /** @param null|callable(): DateTimeInterface|string $clock */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock instanceof Closure
            ? $clock
            : Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE)));
    }

    /** @return array{subject: string, html: string, text: string, reply_to: string, attachments: array} */
    public function create(array $data): array
    {
        $formType = $data['form_type'] ?? null;
        if (!is_string($formType) || !in_array($formType, ['appointment', 'contact', 'application'], true)) {
            throw new InvalidArgumentException('Unknown form type.');
        }

        $name = $this->value($data, 'name');
        $email = $this->value($data, 'email');
        $phone = $this->value($data, 'phone');
        $subjectValue = $this->value($data, 'subject');
        $messageValue = $this->value($data, 'message');
        $position = $this->value($data, 'position');

        $subject = match ($formType) {
            'appointment' => '[Site SODAP-CI] Demande de rendez-vous',
            'contact' => '[Site SODAP-CI] Contact — ' . $subjectValue,
            'application' => '[Site SODAP-CI] Candidature — ' . $position,
        };

        $fields = [
            ['label' => 'Nom', 'value' => $name],
            ['label' => 'Adresse e-mail', 'value' => $email],
        ];

        if ($formType !== 'application') {
            $fields[] = ['label' => 'Téléphone', 'value' => $phone];
            $fields[] = ['label' => 'Sujet', 'value' => $subjectValue];
            $fields[] = ['label' => 'Message', 'value' => $messageValue];
        } else {
            $fields[] = ['label' => 'Poste recherché', 'value' => $position];
        }

        $date = $this->timestamp();
        $fields[] = ['label' => 'Date d’envoi', 'value' => $date];

        $htmlLines = ['<h1>' . $this->html($subject) . '</h1>'];
        $textLines = [$subject];
        foreach ($fields as $field) {
            $htmlValue = $this->html($field['value']);
            if ($field['label'] === 'Message') {
                $htmlValue = nl2br($htmlValue, false);
            }
            $htmlLines[] = '<p><strong>' . $this->html($field['label']) . ' :</strong> ' . $htmlValue . '</p>';
            $textLines[] = $field['label'] . ' : ' . $field['value'];
        }

        $attachments = [];
        if ($formType === 'application' && isset($data['attachments']) && is_array($data['attachments'])) {
            $attachments = $data['attachments'];
        }

        return [
            'subject' => $subject,
            'html' => implode("\n", $htmlLines),
            'text' => implode("\n", $textLines),
            'reply_to' => $email,
            'attachments' => $attachments,
        ];
    }

    private function value(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        if (is_string($value)) {
            return $this->normalizeNewlines($value);
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $this->normalizeNewlines((string) $value);
        }
        return '';
    }

    private function normalizeNewlines(string $value): string
    {
        return str_replace(["\r\n", "\r"], "\n", $value);
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function timestamp(): string
    {
        $value = ($this->clock)();
        if ($value instanceof DateTimeInterface) {
            $date = DateTimeImmutable::createFromInterface($value);
        } elseif (is_string($value)) {
            $date = new DateTimeImmutable($value);
        } else {
            throw new LogicException('The message clock must return a date.');
        }

        return $date->setTimezone(new DateTimeZone(self::TIMEZONE))->format('Y-m-d\TH:i:sP');
    }
}
