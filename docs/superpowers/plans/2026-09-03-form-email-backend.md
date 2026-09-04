# Backend d’envoi des formulaires par e-mail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Envoyer les formulaires de contact et de candidature vers `carriere@lasodapci.com`, avec CV et lettre de motivation en pièces jointes, sans exposer de secret dans le site statique.

**Architecture:** Un Cloudflare Worker reçoit deux requêtes POST multipart sur la route `/api/*` du domaine du site, valide les données et appelle l’API Resend avec une clé injectée comme secret. `contact.html` délègue la soumission à un script front-end qui affiche des retours accessibles ; les fichiers ne sont jamais stockés par le Worker.

**Tech Stack:** Cloudflare Workers, JavaScript ES modules, Web Fetch API, Resend REST API, Node.js test runner, HTML statique.

---

### Task 1: Créer le socle testé du Worker

**Files:**
- Create: `worker/package.json`
- Create: `worker/wrangler.toml`
- Create: `worker/src/index.js`
- Create: `worker/test/index.test.js`

- [ ] **Step 1: Écrire les tests en échec pour le routage et la validation contact**

Créer `worker/test/index.test.js` avec ces cas : une origine non autorisée reçoit 403, une route inconnue reçoit 404, un contact incomplet reçoit 400, et un contact valide appelle Resend avec le destinataire et `reply_to` attendus.

```js
import test from "node:test";
import assert from "node:assert/strict";
import worker from "../src/index.js";

const env = {
  ALLOWED_ORIGIN: "https://www.lasodapci.com",
  FROM_EMAIL: "SODAP-CI <contact@lasodapci.com>",
  RESEND_API_KEY: "re_test"
};

function request(path, form, origin = env.ALLOWED_ORIGIN) {
  return new Request(`https://api.example.com${path}`, {
    method: "POST",
    headers: { Origin: origin },
    body: form
  });
}

test("refuses an unauthorized origin", async () => {
  const response = await worker.fetch(request("/api/contact", new FormData(), "https://evil.example"), env);
  assert.equal(response.status, 403);
});

test("returns 404 for an unknown route", async () => {
  const response = await worker.fetch(request("/api/unknown", new FormData()), env);
  assert.equal(response.status, 404);
});

test("rejects an incomplete contact form", async () => {
  const form = new FormData();
  form.set("name", "Awa");
  const response = await worker.fetch(request("/api/contact", form), env);
  assert.equal(response.status, 400);
});

test("sends a valid contact request through Resend", async () => {
  const originalFetch = globalThis.fetch;
  let payload;
  globalThis.fetch = async (_url, options) => {
    payload = JSON.parse(options.body);
    return new Response(JSON.stringify({ id: "email_1" }), { status: 200 });
  };
  const form = new FormData();
  form.set("name", "Awa Kouassi");
  form.set("email", "awa@example.com");
  form.set("phone", "+225 01 02 03 04 05");
  form.set("subject", "Partenariat");
  form.set("message", "Bonjour");
  const response = await worker.fetch(request("/api/contact", form), env);
  globalThis.fetch = originalFetch;
  assert.equal(response.status, 202);
  assert.deepEqual(payload.to, ["carriere@lasodapci.com"]);
  assert.equal(payload.reply_to, "awa@example.com");
});
```

- [ ] **Step 2: Vérifier que les tests échouent faute de Worker**

Run: `npm test --prefix worker`

Expected: échec car `worker/src/index.js` n’existe pas encore.

- [ ] **Step 3: Ajouter la configuration minimale**

Créer `worker/package.json` :

```json
{
  "name": "sodapci-form-worker",
  "private": true,
  "type": "module",
  "scripts": {
    "test": "node --test",
    "dev": "wrangler dev",
    "deploy": "wrangler deploy"
  },
  "devDependencies": {
    "wrangler": "^4.0.0"
  }
}
```

Créer `worker/wrangler.toml` :

```toml
name = "sodapci-form-api"
main = "src/index.js"
compatibility_date = "2026-09-03"
```

- [ ] **Step 4: Créer le Worker minimal qui satisfait les routes et réponses de base**

Créer `worker/src/index.js` et exporter un objet Worker :

```js
const RECIPIENT = "carriere@lasodapci.com";

function json(body, status, origin) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "Access-Control-Allow-Origin": origin,
      "Vary": "Origin"
    }
  });
}

export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    if (request.method === "OPTIONS") {
      return new Response(null, { status: 204, headers: {
        "Access-Control-Allow-Origin": origin === env.ALLOWED_ORIGIN ? origin : "null",
        "Access-Control-Allow-Methods": "POST, OPTIONS",
        "Access-Control-Allow-Headers": "Content-Type",
        "Vary": "Origin"
      }});
    }
    if (origin !== env.ALLOWED_ORIGIN) return json({ error: "Origine non autorisée." }, 403, "null");
    if (request.method !== "POST") return json({ error: "Méthode non autorisée." }, 405, origin);
    if (new URL(request.url).pathname !== "/api/contact") return json({ error: "Route introuvable." }, 404, origin);
    return json({ error: "Champs requis manquants." }, 400, origin);
  }
};
```

- [ ] **Step 5: Lancer les tests et confirmer la progression**

Run: `npm test --prefix worker`

Expected: les routes 403 et 404 passent ; les tests de formulaire restent en échec jusqu’à la Task 2.

- [ ] **Step 6: Commit**

```powershell
git add worker/package.json worker/wrangler.toml worker/src/index.js worker/test/index.test.js
git commit -m "feat: scaffold form email worker"
```

### Task 2: Implémenter l’envoi sécurisé et les pièces jointes

**Files:**
- Modify: `worker/src/index.js`
- Modify: `worker/test/index.test.js`

- [ ] **Step 1: Écrire les tests en échec pour les candidatures**

Ajouter des tests qui vérifient : candidature sans CV → 400 ; candidature avec `.exe` → 400 ; candidature avec deux PDF de 6 Mo → 413 ; candidature valide avec deux PDF → 202 et deux entrées dans `attachments` de la requête Resend.

```js
test("attaches CV and cover letter to a valid application", async () => {
  const originalFetch = globalThis.fetch;
  let payload;
  globalThis.fetch = async (_url, options) => {
    payload = JSON.parse(options.body);
    return new Response(JSON.stringify({ id: "email_2" }), { status: 200 });
  };
  const form = new FormData();
  form.set("name", "Koffi Konan");
  form.set("position", "Technicien agricole");
  form.set("email", "koffi@example.com");
  form.set("cv", new File(["cv"], "cv.pdf", { type: "application/pdf" }));
  form.set("coverLetter", new File(["lettre"], "lettre.pdf", { type: "application/pdf" }));
  const response = await worker.fetch(request("/api/candidature", form), env);
  globalThis.fetch = originalFetch;
  assert.equal(response.status, 202);
  assert.equal(payload.attachments.length, 2);
});
```

- [ ] **Step 2: Vérifier l’échec des tests de candidature**

Run: `npm test --prefix worker`

Expected: échec car `/api/candidature` n’est pas encore gérée.

- [ ] **Step 3: Implémenter validation, honeypot et appel Resend**

Compléter `worker/src/index.js` avec :

```js
const MAX_FILE_BYTES = 5 * 1024 * 1024;
const ALLOWED_TYPES = new Set([
  "application/pdf",
  "application/msword",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
]);

function required(form, key) {
  return String(form.get(key) || "").trim();
}

function validEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

async function attachment(file) {
  if (!(file instanceof File) || !file.name || !ALLOWED_TYPES.has(file.type)) {
    throw new Response(JSON.stringify({ error: "Le fichier doit être au format PDF, DOC ou DOCX." }), { status: 400 });
  }
  if (file.size > MAX_FILE_BYTES) {
    throw new Response(JSON.stringify({ error: "Chaque fichier doit faire au maximum 5 Mo." }), { status: 413 });
  }
  const bytes = new Uint8Array(await file.arrayBuffer());
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return { filename: file.name, content: btoa(binary) };
}

async function sendEmail(env, payload) {
  const response = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${env.RESEND_API_KEY}`,
      "Content-Type": "application/json",
      "User-Agent": "sodapci-form-worker"
    },
    body: JSON.stringify(payload)
  });
  if (!response.ok) throw new Error("Resend rejected the email");
}
```

La route contact doit exiger `name`, `email`, `subject` et `message`, ignorer silencieusement une soumission dont `website` est rempli, et envoyer un e-mail avec sujet `Contact — ${subject}`. La route candidature doit exiger `name`, `email`, `position`, `cv` et `coverLetter`, puis envoyer le sujet `Candidature — ${position}` avec les deux pièces jointes. Dans les deux cas, les erreurs Resend retournent `502` avec le message public `Envoi temporairement indisponible.`

- [ ] **Step 4: Vérifier tous les scénarios du Worker**

Run: `npm test --prefix worker`

Expected: tous les tests passent.

- [ ] **Step 5: Commit**

```powershell
git add worker/src/index.js worker/test/index.test.js
git commit -m "feat: send contact and application emails"
```

### Task 3: Relier les formulaires à l’API

**Files:**
- Create: `js/contact-forms.js`
- Modify: `contact.html:583-614`
- Modify: `contact.html:639-663`
- Modify: `tools/check-contact-page.mjs`

- [ ] **Step 1: Écrire les contrôles en échec de l’intégration**

Dans `tools/check-contact-page.mjs`, ajouter les assertions suivantes :

```js
assert(contact.includes('data-form-endpoint="/api/contact"'), "contact form must target /api/contact");
assert(contact.includes('data-form-endpoint="/api/candidature"'), "application form must target /api/candidature");
assert(contact.includes('name="website"'), "forms must include the honeypot field");
assert(contact.includes('src="js/contact-forms.js"'), "contact page must load contact form behavior");
```

- [ ] **Step 2: Vérifier l’échec du contrôle**

Run: `npm run check:contact`

Expected: échec avec `contact form must target /api/contact`.

- [ ] **Step 3: Mettre à jour la structure des formulaires**

Pour le contact, remplacer `action="mailto:..."` par `action="/api/contact" data-form-endpoint="/api/contact" novalidate`, renommer les champs en `name`, `email`, `phone`, `subject`, `message`, et ajouter avant le bouton :

```html
<input class="sr-only" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" />
<p class="mt-4 text-sm" data-form-status role="status" aria-live="polite"></p>
```

Pour la candidature, définir `action="/api/candidature" data-form-endpoint="/api/candidature" novalidate`, utiliser les noms `name`, `position`, `email`, `cv`, `coverLetter`, ajouter le même honeypot/statut et conserver `enctype="multipart/form-data"`. Ajouter `accept=".pdf,.doc,.docx"` et `data-max-file-bytes="5242880"` sur les deux champs fichier.

- [ ] **Step 4: Créer l’envoi front-end asynchrone**

Créer `js/contact-forms.js` :

```js
(() => {
  const forms = document.querySelectorAll("[data-form-endpoint]");
  forms.forEach((form) => {
    const status = form.querySelector("[data-form-status]");
    const submit = form.querySelector('[type="submit"]');
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!form.reportValidity()) return;
      status.textContent = "Envoi en cours…";
      submit.disabled = true;
      try {
        const response = await fetch(form.dataset.formEndpoint, { method: "POST", body: new FormData(form) });
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || "Envoi impossible.");
        form.reset();
        status.textContent = "Votre demande a bien été envoyée.";
      } catch (error) {
        status.textContent = error.message || "Envoi impossible. Veuillez réessayer.";
      } finally {
        submit.disabled = false;
      }
    });
  });
})();
```

Charger ce script à la fin de `contact.html` avec `<script src="js/contact-forms.js"></script>`.

- [ ] **Step 5: Vérifier les contrôles existants et nouveaux**

Run: `npm run check:contact && npm run check`

Expected: les deux commandes réussissent.

- [ ] **Step 6: Commit**

```powershell
git add contact.html js/contact-forms.js tools/check-contact-page.mjs
git commit -m "feat: submit website forms to email API"
```

### Task 4: Documenter et préparer le déploiement sécurisé

**Files:**
- Create: `worker/.dev.vars.example`
- Create: `worker/README.md`

- [ ] **Step 1: Créer l’exemple de variables sans secret**

Créer `worker/.dev.vars.example` :

```dotenv
RESEND_API_KEY=re_replace_with_a_test_key
FROM_EMAIL=SODAP-CI <contact@lasodapci.com>
ALLOWED_ORIGIN=http://127.0.0.1:8787
```

- [ ] **Step 2: Documenter les commandes et secrets de production**

Créer `worker/README.md` avec les commandes exactes :

```powershell
cd worker
npm install
npm test
npx wrangler secret put RESEND_API_KEY
npx wrangler secret put FROM_EMAIL
npx wrangler secret put ALLOWED_ORIGIN
npm run deploy
```

Préciser que `FROM_EMAIL` doit utiliser un domaine validé dans Resend, que les trois valeurs ne doivent jamais être ajoutées au dépôt, et que le Worker doit être associé dans Cloudflare à la route `https://votre-domaine/api/*`. Ajouter la configuration manuelle d’une règle Cloudflare de limitation de débit pour `POST /api/*`.

- [ ] **Step 3: Vérifier l’absence de clé dans les fichiers suivis**

Run: `rg -n --hidden -S 're_[A-Za-z0-9]{10,}|RESEND_API_KEY=' --glob '!worker/.dev.vars.example' .`

Expected: aucune clé réelle ; seules les références de documentation et les noms de variables sont présents.

- [ ] **Step 4: Exécuter la vérification complète**

Run: `npm test --prefix worker && npm run check && git diff --check`

Expected: toutes les commandes réussissent sans avertissement.

- [ ] **Step 5: Commit**

```powershell
git add worker/.dev.vars.example worker/README.md
git commit -m "docs: add form email deployment guide"
```
