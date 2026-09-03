# Backend d’envoi des formulaires par e-mail

## Objectif

Permettre aux formulaires de contact et de candidature de transmettre leurs données à `carriere@lasodapci.com`, avec le CV et la lettre de motivation reçus directement en pièces jointes. Le site public reste un site statique.

## Architecture

Une API Cloudflare Worker expose deux routes HTTPS :

- `POST /api/contact` reçoit le formulaire de contact.
- `POST /api/candidature` reçoit le formulaire de candidature en `multipart/form-data`.

Le navigateur envoie les formulaires vers ces routes. Le Worker valide les données, applique une limitation simple des requêtes et appelle l’API Resend. La clé `RESEND_API_KEY` est enregistrée comme secret du Worker, jamais dans le HTML ou JavaScript public. Resend envoie les messages à `carriere@lasodapci.com` depuis une adresse du domaine vérifié.

Le formulaire de candidature envoie le CV et la lettre de motivation comme pièces jointes. Les fichiers autorisés sont PDF, DOC et DOCX, avec une limite de 5 Mo par fichier. Cette limite maintient la taille de l’e-mail dans les limites de Resend une fois les fichiers encodés.

## Flux

1. L’utilisateur complète l’un des deux formulaires de `contact.html`.
2. Le JavaScript du site valide les champs requis et envoie les données en HTTPS au Worker.
3. Le Worker vérifie l’origine autorisée, les champs, les types et tailles de fichiers, puis écarte les soumissions de robot grâce à un champ honeypot.
4. Le Worker transmet un e-mail structuré à Resend avec `reply_to` défini sur l’adresse fournie par le visiteur ; pour une candidature, il ajoute le CV et la lettre de motivation en pièces jointes.
5. Le Worker retourne une réponse JSON. L’interface affiche un message de succès ou une erreur exploitable sans divulguer de détails internes.

## Sécurité et confidentialité

- La clé Resend et l’adresse expéditrice sont des secrets de déploiement.
- Seul le domaine public du site est autorisé par CORS ; l’URL locale est autorisée uniquement en développement.
- Aucune candidature n’est stockée par le Worker après l’envoi ; les fichiers ne sont conservés que le temps de traiter la requête.
- Les erreurs et journaux ne doivent inclure ni les contenus des messages ni les données des pièces jointes.
- Les requêtes invalides, trop volumineuses ou trop fréquentes reçoivent une réponse d’erreur sans appel à Resend.

## Fichiers prévus

- `worker/src/index.js` : routes, validation et appel à Resend.
- `worker/wrangler.toml` : configuration Cloudflare Worker sans secret.
- `worker/package.json` : scripts de test et déploiement.
- `contact.html` : formulaires reliés aux API, messages accessibles de succès/erreur et honeypot.
- `js/contact-forms.js` : envoi asynchrone et état de l’interface.
- `tools/check-contact-page.mjs` : contrôle de la présence des attributs et scripts requis.
- `worker/test/index.test.js` : tests de validation, CORS, échec Resend et succès d’envoi.

## Configuration de déploiement

Avant la mise en ligne, le propriétaire du domaine devra :

1. Créer un compte Cloudflare et un compte Resend.
2. Vérifier un domaine expéditeur dans Resend en ajoutant les enregistrements DNS demandés.
3. Définir `RESEND_API_KEY`, `ALLOWED_ORIGIN` et `FROM_EMAIL` comme secrets/configuration du Worker.
4. Déployer le Worker puis renseigner son URL publique dans le site statique.

## Critères de réussite

- Un message de contact valide arrive à `carriere@lasodapci.com` et permet de répondre au visiteur.
- Une candidature valide arrive avec les deux fichiers en pièces jointes.
- Les demandes invalides, fichiers non autorisés et fichiers trop lourds sont refusés clairement.
- La clé Resend n’apparaît dans aucun fichier suivi par Git ni dans le navigateur.
- Les tests du Worker et les contrôles existants du site passent.
