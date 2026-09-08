# Email Forms Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Envoyer les trois formulaires SODAP-CI vers `carriere@lasodapci.com` par SMTP WorkCloud, avec CV et lettre de motivation joints aux candidatures.

**Architecture:** Les pages HTML publient des formulaires `multipart/form-data` améliorés par un petit module JavaScript commun. Un contrôleur PHP unique sélectionne un schéma de validation selon `form_type`, applique l'antispam et la limite d'envoi, puis délègue l'email à PHPMailer. Les règles de validation sont isolées du transport SMTP afin d'être testées sans envoyer de vrais emails.

**Tech Stack:** HTML5, JavaScript navigateur sans dépendance, PHP 8.1+, PHPMailer 6 via Composer, scripts de vérification Node.js existants, tests PHP natifs.

---

## Structure des fichiers

- Modifier `index.html` : transformer le bloc de rendez-vous en formulaire fonctionnel.
- Modifier `contact.html` : connecter les formulaires de contact et de candidature.
- Créer `js/forms.js` : gérer l'envoi asynchrone et les retours accessibles.
- Modifier `tools/check-contact-page.mjs` : vérifier les contrats HTML des formulaires de contact.
- Créer `tools/check-index-form.mjs` : vérifier le contrat HTML du formulaire d'accueil.
- Modifier `package.json` : ajouter la vérification du formulaire d'accueil.
- Créer `composer.json` et générer `composer.lock` : installer PHPMailer et définir l'autoload PHP.
- Créer `config/mail.example.php` : documenter la configuration SMTP attendue.
- Modifier `.gitignore` : exclure les secrets et fichiers temporaires.
- Créer `api/src/FormValidator.php` : normaliser et valider les champs et pièces jointes.
- Créer `api/src/MessageFactory.php` : produire le sujet et les corps texte/HTML.
- Créer `api/src/RateLimiter.php` : limiter les soumissions répétées par IP.
- Créer `api/src/SmtpMailer.php` : configurer PHPMailer et transmettre le message.
- Créer `api/send-form.php` : orchestrer la requête HTTP et la réponse JSON/redirection.
- Créer `tests/php/bootstrap.php` : assertions et doubles de test minimaux.
- Créer `tests/php/FormValidatorTest.php` : tester les schémas et fichiers.
- Créer `tests/php/MessageFactoryTest.php` : tester les emails construits.
- Créer `tests/php/RateLimiterTest.php` : tester la limitation d'envoi.
- Créer `tests/php/EndpointTest.php` : tester les réponses du contrôleur sans SMTP réel.
- Créer `docs/deploiement-formulaires-workcloud.md` : guider la configuration et le test final.

### Task 1: Verrouiller le contrat HTML des trois formulaires

**Files:**
- Modify: `tools/check-contact-page.mjs`
- Create: `tools/check-index-form.mjs`
- Modify: `package.json`
- Modify: `index.html:934-974`
- Modify: `contact.html:583-664`

- [ ] **Step 1: Écrire les vérifications HTML qui échouent**

Ajouter dans `tools/check-contact-page.mjs` les deux formulaires par identifiant, puis exiger leurs contrats :

```js
const contactForm = html.match(/<form id="contact-form"[\s\S]*?<\/form>/)?.[0] ?? "";
const applicationForm = html.match(/<form id="application-form"[\s\S]*?<\/form>/)?.[0] ?? "";

for (const [label, form, type] of [
  ["contact", contactForm, "contact"],
  ["application", applicationForm, "application"],
]) {
  if (!form) failures.push(`Missing ${label} form.`);
  for (const snippet of [
    'action="api/send-form.php"',
    'method="post"',
    'enctype="multipart/form-data"',
    `name="form_type" value="${type}"`,
    'name="website"',
    'data-email-form',
    'data-form-status',
  ]) {
    if (!form.includes(snippet)) failures.push(`${label} form missing: ${snippet}`);
  }
}

for (const snippet of ['name="cv"', 'name="cover_letter"', 'name="email"']) {
  if (!applicationForm.includes(snippet)) failures.push(`application form missing: ${snippet}`);
}
```

Créer `tools/check-index-form.mjs` :

```js
import { readFileSync } from "node:fs";

const html = readFileSync("index.html", "utf8");
const form = html.match(/<form id="appointment-form"[\s\S]*?<\/form>/)?.[0] ?? "";
const required = [
  'action="api/send-form.php"',
  'method="post"',
  'enctype="multipart/form-data"',
  'name="form_type" value="appointment"',
  'name="name"',
  'name="email"',
  'name="phone"',
  'name="subject"',
  'name="message"',
  'name="website"',
  'data-email-form',
  'data-form-status',
];
const failures = required.filter((snippet) => !form.includes(snippet));
if (failures.length) {
  console.error("Homepage form verification failed:");
  failures.forEach((snippet) => console.error(`- Missing: ${snippet}`));
  process.exit(1);
}
console.log("Homepage form verification passed.");
```

Ajouter à `package.json` :

```json
"check:index-form": "node tools/check-index-form.mjs",
"check": "npm run check:setup && npm run check:index && npm run check:contact && npm run check:index-form"
```

- [ ] **Step 2: Exécuter les vérifications et constater l'échec**

Run: `npm run check:contact && npm run check:index-form`

Expected: FAIL, car les identifiants, actions et attributs requis ne sont pas encore présents.

- [ ] **Step 3: Transformer le bloc d'accueil en vrai formulaire**

Remplacer le conteneur interne du formulaire de `index.html` par cette structure, en conservant les classes visuelles existantes :

```html
<form id="appointment-form" class="home-form-panel home-card-tilt bg-white p-8 md:p-10"
  action="api/send-form.php" method="post" enctype="multipart/form-data" data-email-form>
  <input type="hidden" name="form_type" value="appointment">
  <div class="hidden" aria-hidden="true">
    <label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  </div>
  <h2 class="mb-8 font-roboto text-2xl font-bold uppercase text-gray-900">Demande de Rendez-vous / Contact</h2>
  <p class="mt-4 hidden rounded-lg px-4 py-3 text-sm font-semibold" data-form-status aria-live="polite"></p>
</form>
```

Dans la grille existante, appliquer précisément `name="name" required`, `name="email" required`, `name="phone"`, `name="subject" required` et `name="message" required minlength="10"`. Donner à chaque option du service une valeur non vide et stable : `rehabilitation`, `reboisement`, `elevage`, `agriculture`, `location`.

- [ ] **Step 4: Connecter les deux formulaires de contact**

Appliquer au premier formulaire :

```html
<form id="contact-form" class="contact-form-panel rounded-2xl p-6 lg:p-8"
  action="api/send-form.php" method="post" enctype="multipart/form-data" data-email-form>
  <input type="hidden" name="form_type" value="contact">
  <div class="hidden" aria-hidden="true">
    <label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  </div>
  <p class="mt-4 hidden rounded-lg px-4 py-3 text-sm font-semibold sm:col-span-2" data-form-status aria-live="polite"></p>
</form>
```

Dans la grille existante, remplacer les noms accentués par `name`, `email`, `phone`, `subject` et `message`. Conserver `required` sur le nom, l'email et le message, et ajouter `minlength="10"` au message.

Appliquer au second formulaire :

```html
<form id="application-form" class="contact-form-panel rounded-2xl p-6 lg:p-8"
  action="api/send-form.php" method="post" enctype="multipart/form-data" data-email-form>
  <input type="hidden" name="form_type" value="application">
  <div class="hidden" aria-hidden="true">
    <label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
  </div>
  <p class="hidden rounded-lg px-4 py-3 text-sm font-semibold" data-form-status aria-live="polite"></p>
</form>
```

Dans la grille, utiliser `name="name" required`, insérer après le nom un `<input type="email" class="contact-field" name="email" placeholder="Votre email" required>`, puis utiliser `name="position" required`, `name="cv" accept=".pdf,.doc,.docx" required` et `name="cover_letter" accept=".pdf,.doc,.docx" required`.

Sur les deux fichiers, ajouter avant `</body>` :

```html
<script src="js/forms.js"></script>
```

- [ ] **Step 5: Vérifier que les contrats HTML passent**

Run: `npm run check:contact && npm run check:index-form`

Expected: les deux commandes affichent `passed`.

- [ ] **Step 6: Commit**

```bash
git add index.html contact.html tools/check-contact-page.mjs tools/check-index-form.mjs package.json
git commit -m "feat: connect site forms to email endpoint"
```

### Task 2: Ajouter le retour utilisateur sans rechargement

**Files:**
- Create: `js/forms.js`
- Create: `tools/check-form-script.mjs`
- Modify: `package.json`

- [ ] **Step 1: Écrire la vérification statique qui échoue**

Créer `tools/check-form-script.mjs` :

```js
import { readFileSync } from "node:fs";

const source = readFileSync("js/forms.js", "utf8");
const required = [
  '[data-email-form]',
  "new FormData(form)",
  'headers: { Accept: "application/json" }',
  "response.ok",
  "form.reset()",
  'status.setAttribute("role", "alert")',
];
const missing = required.filter((snippet) => !source.includes(snippet));
if (missing.length) {
  console.error("Form script verification failed:");
  missing.forEach((snippet) => console.error(`- Missing: ${snippet}`));
  process.exit(1);
}
console.log("Form script verification passed.");
```

Ajouter `"check:forms-js": "node tools/check-form-script.mjs"` à `scripts` et l'ajouter à la fin de `check`.

- [ ] **Step 2: Constater l'échec**

Run: `npm run check:forms-js`

Expected: FAIL avec `ENOENT` puisque `js/forms.js` n'existe pas.

- [ ] **Step 3: Créer le module d'envoi**

Créer `js/forms.js` :

```js
(() => {
  "use strict";

  const showStatus = (status, message, ok) => {
    status.textContent = message;
    status.classList.remove("hidden", "bg-green-50", "text-green-800", "bg-red-50", "text-red-800");
    status.classList.add(ok ? "bg-green-50" : "bg-red-50", ok ? "text-green-800" : "text-red-800");
    status.setAttribute("role", "alert");
  };

  document.querySelectorAll("[data-email-form]").forEach((form) => {
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const status = form.querySelector("[data-form-status]");
      const button = form.querySelector('[type="submit"]');
      const originalLabel = button.textContent;
      button.disabled = true;
      button.textContent = "Envoi en cours…";
      status.classList.add("hidden");

      try {
        const response = await fetch(form.action, {
          method: "POST",
          body: new FormData(form),
          headers: { Accept: "application/json" },
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.message || "Envoi impossible.");
        form.reset();
        showStatus(status, result.message, true);
      } catch (error) {
        showStatus(status, error.message || "Une erreur est survenue. Veuillez réessayer.", false);
      } finally {
        button.disabled = false;
        button.textContent = originalLabel;
      }
    });
  });
})();
```

- [ ] **Step 4: Vérifier le module**

Run: `npm run check:forms-js`

Expected: `Form script verification passed.`

- [ ] **Step 5: Commit**

```bash
git add js/forms.js tools/check-form-script.mjs package.json
git commit -m "feat: show inline form delivery status"
```

### Task 3: Installer PHPMailer et protéger la configuration

**Files:**
- Create: `composer.json`
- Create: `composer.lock`
- Create: `config/mail.example.php`
- Modify: `.gitignore`

- [ ] **Step 1: Vérifier les prérequis PHP locaux**

Run: `php --version && composer --version`

Expected: PHP 8.1 ou supérieur et Composer 2.x. Si les commandes sont absentes, installer PHP et Composer avant de continuer ; ne pas écrire le traitement PHP sans pouvoir exécuter ses tests.

- [ ] **Step 2: Définir la dépendance et l'autoload**

Créer `composer.json` :

```json
{
  "name": "sodapci/website-forms",
  "description": "Secure email delivery for SODAP-CI website forms",
  "type": "project",
  "require": {
    "php": ">=8.1",
    "ext-fileinfo": "*",
    "phpmailer/phpmailer": "^6.10"
  },
  "autoload": {
    "psr-4": {
      "Sodapci\\Forms\\": "api/src/"
    }
  },
  "scripts": {
    "test": "php tests/php/run.php"
  }
}
```

- [ ] **Step 3: Installer la dépendance**

Run: `composer install`

Expected: `composer.lock` et `vendor/autoload.php` sont créés sans erreur.

- [ ] **Step 4: Créer l'exemple de configuration**

Créer `config/mail.example.php` :

```php
<?php
declare(strict_types=1);

return [
    'smtp_host' => 'mail.lasodapci.com',
    'smtp_port' => 465,
    'smtp_encryption' => 'ssl',
    'smtp_username' => 'carriere@lasodapci.com',
    'smtp_password' => 'REMPLACER_SUR_LE_SERVEUR',
    'from_email' => 'carriere@lasodapci.com',
    'from_name' => 'Site SODAP-CI',
    'recipient_email' => 'carriere@lasodapci.com',
    'allowed_hosts' => ['lasodapci.com', 'www.lasodapci.com'],
    'rate_limit_count' => 5,
    'rate_limit_window_seconds' => 3600,
];
```

Les valeurs `smtp_host`, `smtp_port` et `smtp_encryption` sont des exemples : utiliser les valeurs exactes affichées dans WorkCloud/cPanel lors du déploiement.

- [ ] **Step 5: Exclure les secrets et données temporaires**

Ajouter à `.gitignore` :

```gitignore
/config/mail.php
/var/
```

- [ ] **Step 6: Vérifier et commit**

Run: `composer validate --strict`

Expected: `composer.json is valid`.

```bash
git add composer.json composer.lock config/mail.example.php .gitignore
git commit -m "build: add authenticated SMTP dependency"
```

### Task 4: Valider les champs et les pièces jointes

**Files:**
- Create: `tests/php/bootstrap.php`
- Create: `tests/php/run.php`
- Create: `tests/php/FormValidatorTest.php`
- Create: `api/src/FormValidator.php`

- [ ] **Step 1: Créer le mini-harnais de test**

Créer `tests/php/bootstrap.php` :

```php
<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function test(string $name, callable $callback): void
{
    try {
        $callback();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
        $GLOBALS['test_failures'] = ($GLOBALS['test_failures'] ?? 0) + 1;
    }
}

function assertSameValue(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $callback, string $expectedMessage): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $error) {
        assertSameValue($expectedMessage, $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected InvalidArgumentException.');
}
```

Créer `tests/php/run.php` :

```php
<?php
declare(strict_types=1);

$GLOBALS['test_failures'] = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) require $file;
exit($GLOBALS['test_failures'] > 0 ? 1 : 0);
```

- [ ] **Step 2: Écrire les tests de validation qui échouent**

Créer `tests/php/FormValidatorTest.php` avec des fichiers temporaires générés pendant le test :

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\FormValidator;

test('validates a contact submission', function (): void {
    $validator = new FormValidator(fn (): bool => true);
    $data = $validator->validate([
        'form_type' => 'contact', 'name' => 'Awa Kouassi',
        'email' => 'awa@example.com', 'phone' => '+225 01 02 03 04 05',
        'subject' => 'Partenariat', 'message' => 'Bonjour, je souhaite échanger avec vous.',
        'website' => '',
    ], []);
    assertSameValue('contact', $data['form_type']);
    assertSameValue('awa@example.com', $data['email']);
});

test('rejects an invalid email', function (): void {
    $validator = new FormValidator(fn (): bool => true);
    assertThrows(fn () => $validator->validate([
        'form_type' => 'contact', 'name' => 'Awa', 'email' => 'incorrect',
        'subject' => 'Projet', 'message' => 'Un message suffisamment long.', 'website' => '',
    ], []), 'Veuillez saisir une adresse email valide.');
});

test('silently identifies honeypot submissions', function (): void {
    $validator = new FormValidator(fn (): bool => true);
    assertThrows(fn () => $validator->validate([
        'form_type' => 'contact', 'website' => 'robot.example',
    ], []), 'Soumission refusée.');
});

test('requires both application files', function (): void {
    $validator = new FormValidator(fn (): bool => true);
    assertThrows(fn () => $validator->validate([
        'form_type' => 'application', 'name' => 'Jean Koffi',
        'email' => 'jean@example.com', 'position' => 'Technicien', 'website' => '',
    ], []), 'Le CV et la lettre de motivation sont obligatoires.');
});
```

- [ ] **Step 3: Constater l'échec**

Run: `composer test`

Expected: FAIL avec `Class "Sodapci\Forms\FormValidator" not found`.

- [ ] **Step 4: Implémenter la validation minimale**

Créer `api/src/FormValidator.php` avec :

```php
<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use InvalidArgumentException;

final class FormValidator
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024;
    private const ALLOWED_FILES = [
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    private readonly \Closure $uploadVerifier;

    public function __construct(?callable $uploadVerifier = null)
    {
        $this->uploadVerifier = \Closure::fromCallable($uploadVerifier ?? 'is_uploaded_file');
    }

    public function validate(array $post, array $files): array
    {
        if (trim((string)($post['website'] ?? '')) !== '') {
            throw new InvalidArgumentException('Soumission refusée.');
        }
        $type = (string)($post['form_type'] ?? '');
        if (!in_array($type, ['appointment', 'contact', 'application'], true)) {
            throw new InvalidArgumentException('Type de formulaire invalide.');
        }

        $data = ['form_type' => $type];
        $data['name'] = self::text($post, 'name', 2, 120, 'Veuillez saisir votre nom.');
        $data['email'] = self::email($post);

        if ($type === 'application') {
            $data['position'] = self::text($post, 'position', 2, 160, 'Veuillez indiquer le poste ou domaine recherché.');
            if (!isset($files['cv'], $files['cover_letter'])) {
                throw new InvalidArgumentException('Le CV et la lettre de motivation sont obligatoires.');
            }
            $data['attachments'] = [
                $this->file($files['cv'], 'CV'),
                $this->file($files['cover_letter'], 'Lettre de motivation'),
            ];
            return $data;
        }

        $data['phone'] = self::optionalText($post, 'phone', 40);
        $data['subject'] = self::text($post, 'subject', 2, 160, 'Veuillez sélectionner un sujet.');
        $data['message'] = self::text($post, 'message', 10, 5000, 'Veuillez saisir un message d’au moins 10 caractères.');
        return $data;
    }

    private static function email(array $post): string
    {
        $email = trim((string)($post['email'] ?? ''));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            throw new InvalidArgumentException('Veuillez saisir une adresse email valide.');
        }
        return $email;
    }

    private static function text(array $post, string $key, int $min, int $max, string $error): string
    {
        $value = trim((string)($post[$key] ?? ''));
        $length = mb_strlen($value);
        if ($length < $min || $length > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private static function optionalText(array $post, string $key, int $max): string
    {
        $value = trim((string)($post[$key] ?? ''));
        if (mb_strlen($value) > $max) throw new InvalidArgumentException('Le numéro de téléphone est trop long.');
        return $value;
    }

    private function file(array $file, string $label): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException("{$label} : téléversement incomplet.");
        }
        if (($file['size'] ?? 0) < 1 || $file['size'] > self::MAX_FILE_SIZE || !($this->uploadVerifier)($file['tmp_name'])) {
            throw new InvalidArgumentException("{$label} : le fichier doit faire au maximum 5 Mo.");
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::ALLOWED_FILES[$mime])) {
            throw new InvalidArgumentException("{$label} : seuls les fichiers PDF, DOC et DOCX sont acceptés.");
        }
        $base = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo((string)$file['name'], PATHINFO_FILENAME));
        return ['path' => $file['tmp_name'], 'name' => trim($base, '-') . '.' . self::ALLOWED_FILES[$mime], 'mime' => $mime, 'size' => (int)$file['size']];
    }
}
```

Compléter `FormValidatorTest.php` par trois cas utilisant `tempnam()` et `file_put_contents()` : un PDF minimal `%PDF-1.4` est accepté avec le vérificateur injecté, un fichier texte nommé `.pdf` est refusé, et un fichier dont `size` vaut `5 * 1024 * 1024 + 1` est refusé. Supprimer chaque fichier temporaire dans un bloc `finally`.

- [ ] **Step 5: Exécuter les tests**

Run: `composer dump-autoload && composer test`

Expected: tous les tests de `FormValidatorTest.php` affichent `PASS`.

- [ ] **Step 6: Commit**

```bash
git add api/src/FormValidator.php tests/php/bootstrap.php tests/php/run.php tests/php/FormValidatorTest.php
git commit -m "feat: validate form fields and attachments"
```

### Task 5: Construire des emails sûrs et lisibles

**Files:**
- Create: `tests/php/MessageFactoryTest.php`
- Create: `api/src/MessageFactory.php`

- [ ] **Step 1: Écrire les tests qui échouent**

Créer `tests/php/MessageFactoryTest.php` :

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\MessageFactory;

test('builds an escaped contact email', function (): void {
    $message = (new MessageFactory())->create([
        'form_type' => 'contact', 'name' => '<Awa>', 'email' => 'awa@example.com',
        'phone' => '+225 01 02 03 04 05', 'subject' => 'Partenariat',
        'message' => '<script>alert(1)</script>',
    ]);
    assertSameValue('[Site SODAP-CI] Contact — Partenariat', $message['subject']);
    if (str_contains($message['html'], '<script>')) throw new RuntimeException('HTML was not escaped.');
    if (!str_contains($message['text'], '<script>alert(1)</script>')) throw new RuntimeException('Text body lost content.');
});

test('builds an application subject', function (): void {
    $message = (new MessageFactory())->create([
        'form_type' => 'application', 'name' => 'Jean Koffi',
        'email' => 'jean@example.com', 'position' => 'Technicien terrain', 'attachments' => [],
    ]);
    assertSameValue('[Site SODAP-CI] Candidature — Technicien terrain', $message['subject']);
});
```

- [ ] **Step 2: Constater l'échec**

Run: `composer test`

Expected: FAIL avec `MessageFactory not found`.

- [ ] **Step 3: Implémenter la fabrique**

Créer `api/src/MessageFactory.php`. La méthode `create(array $data): array` retourne exactement `subject`, `html`, `text`, `reply_to` et `attachments`. Utiliser une table de libellés par `form_type`, `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` pour chaque valeur HTML, et une date ISO calculée avec `new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'))`. Ne jamais inclure un mot de passe, chemin temporaire ou adresse IP dans le corps.

Sujets exacts :

```php
$subjects = [
    'appointment' => '[Site SODAP-CI] Demande de rendez-vous',
    'contact' => '[Site SODAP-CI] Contact — ' . $data['subject'],
    'application' => '[Site SODAP-CI] Candidature — ' . $data['position'],
];
```

- [ ] **Step 4: Vérifier**

Run: `composer test`

Expected: tous les tests, dont les deux tests `MessageFactory`, affichent `PASS`.

- [ ] **Step 5: Commit**

```bash
git add api/src/MessageFactory.php tests/php/MessageFactoryTest.php
git commit -m "feat: build safe form email messages"
```

### Task 6: Limiter les soumissions répétées

**Files:**
- Create: `tests/php/RateLimiterTest.php`
- Create: `api/src/RateLimiter.php`

- [ ] **Step 1: Écrire le test qui échoue**

Créer `tests/php/RateLimiterTest.php` :

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Sodapci\Forms\RateLimiter;

test('blocks the sixth request in one hour', function (): void {
    $dir = sys_get_temp_dir() . '/sodap-rate-' . bin2hex(random_bytes(4));
    $limiter = new RateLimiter($dir, 5, 3600, fn (): int => 1_800_000_000);
    for ($i = 0; $i < 5; $i++) assertSameValue(true, $limiter->consume('192.0.2.10'));
    assertSameValue(false, $limiter->consume('192.0.2.10'));
});
```

- [ ] **Step 2: Constater l'échec**

Run: `composer test`

Expected: FAIL avec `RateLimiter not found`.

- [ ] **Step 3: Implémenter la limitation**

Créer `api/src/RateLimiter.php` avec un constructeur `(string $directory, int $limit, int $windowSeconds, ?callable $clock = null)` et `consume(string $ip): bool`. Hacher l'IP avec SHA-256 pour nommer le fichier, verrouiller le fichier avec `flock(LOCK_EX)`, retirer les timestamps antérieurs à la fenêtre, refuser si le compte atteint la limite, sinon ajouter l'instant courant. Créer le dossier avec les droits `0700` s'il n'existe pas.

- [ ] **Step 4: Vérifier**

Run: `composer test`

Expected: le test de la sixième requête et toute la suite affichent `PASS`.

- [ ] **Step 5: Commit**

```bash
git add api/src/RateLimiter.php tests/php/RateLimiterTest.php
git commit -m "feat: rate limit form submissions"
```

### Task 7: Envoyer via SMTP et exposer le contrôleur HTTP

**Files:**
- Create: `api/src/SmtpMailer.php`
- Create: `api/src/FormController.php`
- Create: `api/send-form.php`
- Create: `tests/php/EndpointTest.php`

- [ ] **Step 1: Écrire les tests du contrôleur qui échouent**

Créer `tests/php/EndpointTest.php` avec un mailer callable qui mémorise le message au lieu de l'envoyer. Tester exactement : méthode GET → code 405 ; origine étrangère → 403 ; validation invalide → 422 ; limite dépassée → 429 ; mailer en échec → 500 avec message générique ; envoi valide → 200 et message `Votre demande a bien été envoyée.`. Vérifier également qu'une réponse JSON contient `Content-Type: application/json` et qu'une requête classique produit une URL de redirection choisie dans une table interne, jamais depuis une valeur fournie par l'utilisateur.

- [ ] **Step 2: Constater l'échec**

Run: `composer test`

Expected: FAIL avec `FormController not found`.

- [ ] **Step 3: Créer le transport SMTP**

Créer `api/src/SmtpMailer.php` :

```php
<?php
declare(strict_types=1);

namespace Sodapci\Forms;

use PHPMailer\PHPMailer\PHPMailer;

final class SmtpMailer
{
    public function __construct(private readonly array $config) {}

    public function send(array $message): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['smtp_username'];
        $mail->Password = $this->config['smtp_password'];
        $mail->Port = (int)$this->config['smtp_port'];
        $mail->SMTPSecure = $this->config['smtp_encryption'];
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        $mail->addAddress($this->config['recipient_email']);
        $mail->addReplyTo($message['reply_to']);
        $mail->Subject = $message['subject'];
        $mail->isHTML(true);
        $mail->Body = $message['html'];
        $mail->AltBody = $message['text'];
        foreach ($message['attachments'] as $file) {
            $mail->addAttachment($file['path'], $file['name'], PHPMailer::ENCODING_BASE64, $file['mime']);
        }
        $mail->send();
    }
}
```

- [ ] **Step 4: Créer le contrôleur testable**

Créer `api/src/FormController.php` avec une méthode `handle(array $server, array $post, array $files): array`. Retourner un tableau `status`, `headers`, `body`. Injecter `FormValidator`, `RateLimiter`, un callable mailer et la configuration. Vérifier :

```php
if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return $this->error(405, 'Méthode non autorisée.');
if (!$this->originAllowed($server)) return $this->error(403, 'Origine non autorisée.');
if (!$this->rateLimiter->consume($server['REMOTE_ADDR'] ?? 'unknown')) {
    return $this->error(429, 'Trop de tentatives. Veuillez réessayer plus tard.');
}
```

Intercepter `InvalidArgumentException` en 422 et tout autre `Throwable` en 500. Journaliser uniquement la classe de l'erreur et un identifiant aléatoire. Pour JSON, sérialiser `['ok' => bool, 'message' => string]`. Pour la navigation classique, rediriger uniquement vers : `../index.html?form_status=success#appointment-form`, `../contact.html?form_status=success#contact-form` ou `../contact.html?form_status=success#application-form`.

- [ ] **Step 5: Créer le point d'entrée**

Créer `api/send-form.php` :

```php
<?php
declare(strict_types=1);

use Sodapci\Forms\FormController;
use Sodapci\Forms\FormValidator;
use Sodapci\Forms\MessageFactory;
use Sodapci\Forms\RateLimiter;
use Sodapci\Forms\SmtpMailer;

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = dirname(__DIR__, 2) . '/sodap-mail-config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Le service est temporairement indisponible.']);
    exit;
}
$config = require $configPath;
$validator = new FormValidator();
$limiter = new RateLimiter(dirname(__DIR__) . '/var/rate-limits', $config['rate_limit_count'], $config['rate_limit_window_seconds']);
$smtp = new SmtpMailer($config);
$controller = new FormController($validator, new MessageFactory(), $limiter, [$smtp, 'send'], $config);
$response = $controller->handle($_SERVER, $_POST, $_FILES);
http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) header("{$name}: {$value}");
echo $response['body'];
```

- [ ] **Step 6: Vérifier PHP et les tests**

Run: `composer test && php -l api/send-form.php && php -l api/src/FormController.php && php -l api/src/SmtpMailer.php`

Expected: tous les tests affichent `PASS` et chaque fichier affiche `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add api/send-form.php api/src/FormController.php api/src/SmtpMailer.php tests/php/EndpointTest.php
git commit -m "feat: deliver validated forms over SMTP"
```

### Task 8: Documenter et vérifier le déploiement WorkCloud

**Files:**
- Create: `docs/deploiement-formulaires-workcloud.md`
- Modify: `tools/check-contact-page.mjs`

- [ ] **Step 1: Ajouter la vérification des limites visibles**

Dans `tools/check-contact-page.mjs`, exiger que le formulaire de candidature contienne `accept=".pdf,.doc,.docx"`, `5 Mo maximum par fichier` et `10 Mo maximum au total`. Exécuter `npm run check:contact` et constater l'échec si le texte de limite n'est pas encore présent, puis mettre à jour le paragraphe d'aide du formulaire.

- [ ] **Step 2: Écrire le guide de déploiement**

Créer `docs/deploiement-formulaires-workcloud.md` avec ces étapes explicites :

1. Dans cPanel, relever le serveur SMTP, le port et le chiffrement de `carriere@lasodapci.com`.
2. Copier tout le site, y compris `vendor`, `api`, `js` et `var`, dans `public_html`.
3. Copier `config/mail.example.php` vers `sodap-mail-config.php` dans le dossier parent de `public_html`, puis renseigner le vrai mot de passe directement dans cPanel.
4. Donner `0600` au fichier de configuration et `0700` au dossier `public_html/var` si l'interface le permet.
5. Régler PHP 8.1+ et vérifier `fileinfo`, `upload_max_filesize=6M`, `post_max_size=12M`, `max_file_uploads>=2`.
6. Activer SSL pour le domaine et tester uniquement avec l'URL HTTPS finale.
7. Envoyer un exemplaire de chaque formulaire ; pour la candidature utiliser deux petits PDF non sensibles.
8. Vérifier la réception, le `Reply-To`, les deux pièces jointes et le dossier indésirables.
9. Supprimer les documents de test reçus après validation.

Inclure une section de diagnostic : code 422 = données/fichiers invalides, 429 = trop de tentatives, 500 = configuration ou SMTP ; consulter le journal PHP sans jamais copier le mot de passe SMTP.

- [ ] **Step 3: Exécuter toutes les vérifications locales**

Run: `npm run check && composer validate --strict && composer test`

Expected: toutes les vérifications Node affichent `passed`, Composer valide le projet, et tous les tests PHP affichent `PASS`.

- [ ] **Step 4: Faire un contrôle manuel dans le navigateur local**

Servir la racine avec `php -S 127.0.0.1:4173`, ouvrir `index.html` et `contact.html`, puis vérifier : mise en page inchangée, tabulation correcte, contraintes `required`, sélection de deux fichiers, bouton désactivé pendant l'envoi et erreur générique puisque les identifiants SMTP de production ne sont pas présents.

- [ ] **Step 5: Commit**

```bash
git add contact.html tools/check-contact-page.mjs docs/deploiement-formulaires-workcloud.md
git commit -m "docs: add WorkCloud form deployment guide"
```

### Task 9: Test SMTP réel après publication

**Files:**
- No repository changes unless the test reveals a defect.

- [ ] **Step 1: Installer la configuration privée**

Créer `sodap-mail-config.php` au-dessus de `public_html` depuis l'exemple, avec les paramètres exacts de WorkCloud. Ne pas transmettre le mot de passe par chat et ne pas placer ce fichier dans Git.

- [ ] **Step 2: Tester les trois parcours**

Envoyer une demande de rendez-vous, un contact et une candidature avec deux PDF de test de moins de 100 Ko. Expected: chaque page confirme l'envoi et trois emails arrivent à `carriere@lasodapci.com`.

- [ ] **Step 3: Contrôler la candidature reçue**

Expected: le sujet contient le poste, le nom et l'email figurent dans le corps, les deux fichiers s'ouvrent, et Répondre cible l'adresse du candidat.

- [ ] **Step 4: Contrôler les erreurs publiques**

Tester un fichier `.exe`, un fichier de plus de 5 Mo et six soumissions rapprochées. Expected: le fichier interdit et le fichier trop lourd sont refusés avec une consigne utile ; la sixième soumission est temporairement limitée ; aucune réponse ne révèle le serveur ou le mot de passe SMTP.

- [ ] **Step 5: Faire la vérification finale du dépôt**

Run: `git status --short && git diff --check`

Expected: aucun secret ni fichier `config/mail.php` suivi, aucune erreur d'espacement, et seules les éventuelles modifications préexistantes de l'utilisateur restent non validées.
