# Envoi des formulaires par email

Date : 2026-09-08

## Objectif

Relier les trois formulaires du site SODAP-CI à la boîte `carriere@lasodapci.com` sur l'hébergement WorkCloud. Les visiteurs doivent pouvoir envoyer leurs informations sans ouvrir leur logiciel de messagerie, et le formulaire de candidature doit transmettre le CV et la lettre de motivation comme pièces jointes.

## Périmètre

La fonctionnalité couvre :

- la demande de rendez-vous de `index.html` ;
- le formulaire général de `contact.html` ;
- le formulaire de candidature de `contact.html` ;
- l'envoi SMTP authentifié vers `carriere@lasodapci.com` ;
- les confirmations et erreurs visibles directement dans la page ;
- la configuration et les vérifications nécessaires au déploiement WorkCloud.

La fonctionnalité ne comprend pas de base de données, d'espace candidat, de suivi des demandes ni de stockage permanent des documents.

## Architecture retenue

Le navigateur transmet chaque formulaire en `multipart/form-data` à un point d'entrée PHP unique, `api/send-form.php`. Un champ caché `form_type` distingue les demandes de la page d'accueil, les contacts et les candidatures.

Le traitement PHP valide les données selon le type de formulaire, construit un message lisible, puis utilise PHPMailer avec le serveur SMTP de WorkCloud. L'adresse d'expédition reste une adresse du domaine `lasodapci.com` afin de préserver la délivrabilité. Lorsque le visiteur fournit son adresse email, celle-ci devient l'adresse de réponse (`Reply-To`) sans être utilisée comme expéditeur.

Les paramètres secrets ne sont jamais inscrits dans le HTML, le JavaScript ou le dépôt Git. Le dépôt fournit uniquement un exemple de configuration. Lors du déploiement, les véritables paramètres SMTP sont placés dans un fichier privé situé au-dessus de `public_html`, ou fournis par des variables d'environnement si l'offre WorkCloud le permet.

## Composants

### Formulaires HTML

Chaque formulaire reçoit :

- un identifiant unique ;
- des attributs `name` cohérents et sans accents ;
- les champs obligatoires adaptés à son usage ;
- `method="post"`, `enctype="multipart/form-data"` et l'action vers le traitement PHP ;
- un champ caché `form_type` ;
- un champ invisible leurre pour bloquer les robots simples ;
- une zone de statut accessible pour annoncer l'envoi, la réussite ou l'erreur.

Le formulaire de la page d'accueil, actuellement composé de champs sans élément `<form>`, devient un véritable formulaire. Les deux formulaires de contact cessent d'utiliser respectivement `mailto:` et `#`.

### Comportement JavaScript

Un module JavaScript commun intercepte l'envoi, utilise `FormData`, désactive temporairement le bouton et affiche le résultat sans recharger la page. Il ne contient aucun secret.

Si JavaScript est désactivé, le formulaire reste utilisable : PHP traite la demande puis redirige vers la page d'origine avec un indicateur de résultat. Cette amélioration progressive évite de rendre l'envoi dépendant du JavaScript.

### Traitement PHP

`api/send-form.php` accepte uniquement les requêtes `POST`. Il :

1. identifie le formulaire autorisé ;
2. rejette les soumissions de robots détectées par le champ leurre ;
3. normalise et valide les champs attendus ;
4. valide les fichiers lorsqu'il s'agit d'une candidature ;
5. compose un sujet et un corps de message propres au formulaire ;
6. envoie le message avec PHPMailer et SMTP ;
7. renvoie une réponse JSON au JavaScript ou une redirection pour un envoi classique.

Les réponses publiques restent génériques et ne révèlent ni identifiants, ni chemins internes, ni détails SMTP.

### Configuration SMTP

Un fichier d'exemple documente les valeurs requises : hôte SMTP WorkCloud, port, chiffrement, nom d'utilisateur, mot de passe, adresse d'expédition et adresse destinataire. La vraie configuration est créée uniquement sur la machine locale de l'administrateur ou sur l'hébergement et reste ignorée par Git.

PHPMailer est installé avec Composer et verrouillé à une version précise. Son chargement passe par l'autoloader Composer.

## Données et contenu des emails

Tous les emails arrivent à `carriere@lasodapci.com` avec un préfixe permettant leur classement :

- `[Site SODAP-CI] Demande de rendez-vous` ;
- `[Site SODAP-CI] Contact — <sujet>` ;
- `[Site SODAP-CI] Candidature — <poste ou domaine>`.

Le corps du message contient uniquement les champs du formulaire concerné, ainsi que la date d'envoi. Le contenu utilisateur est traité comme du texte et échappé dans la version HTML de l'email.

## Pièces jointes

Le formulaire de candidature exige un CV et une lettre de motivation. Chaque fichier :

- doit être au format PDF, DOC ou DOCX ;
- est limité à 5 Mo ;
- est contrôlé à partir de son contenu MIME et pas seulement de son extension ;
- reçoit un nom assaini avant d'être joint au message ;
- n'est jamais conservé durablement par l'application.

La taille cumulée maximale est de 10 Mo. Le formulaire explique clairement ces limites avant l'envoi. Les réglages PHP `upload_max_filesize` et `post_max_size` devront accepter ces valeurs avec une marge suffisante.

## Sécurité et lutte contre les abus

Le serveur applique :

- une liste stricte des formulaires et champs acceptés ;
- une longueur maximale pour chaque texte ;
- la validation des adresses email et des numéros de téléphone ;
- la vérification des types et tailles de fichiers ;
- le refus des en-têtes contenant des retours à la ligne ;
- un champ leurre antispam ;
- une limitation simple du nombre d'envois par adresse IP ;
- le contrôle de l'origine lorsque l'en-tête correspondant est disponible ;
- des journaux techniques sans mot de passe, contenu de message ou document joint.

Une protection CAPTCHA n'est pas ajoutée initialement. Elle pourra l'être si les mesures légères ne suffisent pas après la mise en ligne.

## Expérience utilisateur et erreurs

Pendant l'envoi, le bouton est désactivé et son libellé indique que le traitement est en cours. En cas de réussite, le formulaire est réinitialisé et un message confirme la réception. En cas d'erreur de validation, la page indique ce que le visiteur doit corriger. En cas d'échec SMTP, elle conserve les champs saisis et propose de réessayer ou de contacter directement SODAP-CI.

Les messages sont associés au formulaire concerné, lisibles au clavier et annoncés aux technologies d'assistance avec `aria-live`.

## Vérification

Les vérifications automatisées couvriront :

- la structure et la configuration des trois formulaires ;
- la validation de chaque type de demande ;
- le refus des méthodes et types de formulaires inconnus ;
- le refus des champs invalides, des fichiers trop volumineux et des formats interdits ;
- la construction des sujets et contenus d'email ;
- les réponses de réussite et d'échec sans fuite d'information.

Avant mise en production, un test réel sera réalisé avec une petite pièce jointe et une boîte WorkCloud de test ou la boîte finale. Il faudra confirmer la réception, l'ouverture des deux documents, le champ `Reply-To` et l'absence du message dans les courriers indésirables.

## Déploiement WorkCloud

Le site, le dossier `api`, les dépendances Composer et les ressources statiques sont téléversés dans `public_html`. Le fichier réel de paramètres SMTP est placé au-dessus de ce dossier. Les droits du fichier sont limités au compte d'hébergement.

Après installation, il faudra renseigner les paramètres SMTP exacts fournis par WorkCloud, vérifier le certificat SSL du domaine, puis effectuer les tests de bout en bout. Le mot de passe SMTP ne devra jamais être envoyé dans cette conversation ni ajouté au dépôt.
