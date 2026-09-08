# Déployer les formulaires sur WorkCloud

Ce guide permet d'activer les formulaires de rendez-vous, de contact et de candidature après la publication du site. Les trois formulaires envoient leurs messages à `carriere@lasodapci.com`. Les CV et lettres de motivation ne sont pas conservés par le site.

## 1. Relever les paramètres de messagerie

Dans cPanel, ouvrir **Comptes de messagerie**, puis **Connecter des appareils** pour `carriere@lasodapci.com`. Noter les valeurs exactes indiquées pour :

- le serveur SMTP sortant ;
- le port SMTP ;
- le chiffrement SSL/TLS ;
- le nom d'utilisateur, normalement l'adresse email complète.

Ne jamais envoyer le mot de passe par messagerie instantanée, ne jamais l'inscrire dans Git et ne jamais le placer dans un fichier téléchargeable depuis le site.

## 2. Préparer la configuration privée

Le fichier `config/mail.example.php` est uniquement un modèle. Dans le dossier qui contient `public_html`, créer un fichier nommé `sodap-mail-config.php`. Il doit se trouver à côté de `public_html`, et non à l'intérieur.

Copier le contenu du modèle dans ce fichier privé, puis remplacer :

- `smtp_host`, `smtp_port` et `smtp_encryption` par les valeurs WorkCloud ;
- `smtp_password` par le mot de passe de la boîte ;
- `rate_limit_secret` par une chaîne aléatoire d'au moins 32 caractères.

Pour générer le secret depuis le terminal cPanel :

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Conserver `from_email` et `recipient_email` à `carriere@lasodapci.com`. Donner au fichier les droits `0600` lorsque cPanel permet de les régler.

## 3. Vérifier PHP

Dans **Sélectionner une version de PHP** ou **MultiPHP Manager**, choisir PHP 8.1 ou une version supérieure. Vérifier que les extensions suivantes sont actives :

- `fileinfo` pour reconnaître le contenu réel des documents ;
- `openssl` pour la connexion SMTP chiffrée ;
- `zip` pour vérifier les fichiers DOCX.

Les limites PHP doivent permettre deux pièces jointes de 5 Mo :

```ini
upload_max_filesize = 6M
post_max_size = 12M
max_file_uploads = 2
```

Une valeur supérieure pour `max_file_uploads` est acceptable. Si WorkCloud impose une limite plus basse, réduire également la limite affichée dans le formulaire et celle du validateur PHP.

## 4. Publier les fichiers

Téléverser dans `public_html` les pages et dossiers du site, notamment :

- `index.html` et `contact.html` ;
- `api` ;
- `config/mail.example.php` ;
- `js`, `css`, `img` et `lib` ;
- `vendor`, généré par Composer.

Créer `public_html/var/rate-limits` s'il n'existe pas et lui donner les droits `0700` lorsque cPanel le permet. Le serveur PHP doit pouvoir écrire dans ce dossier.

Ne pas téléverser de fichier contenant un vrai mot de passe dans `public_html`. Le seul fichier réel d'identifiants est `sodap-mail-config.php`, placé au-dessus.

## 5. Activer HTTPS

Dans cPanel, activer le certificat SSL du domaine et forcer l'accès HTTPS. Tester les formulaires uniquement depuis l'adresse finale `https://lasodapci.com` ou `https://www.lasodapci.com`, car les autres origines sont refusées.

## 6. Tester les trois formulaires

Effectuer successivement :

1. une demande de rendez-vous depuis la page d'accueil ;
2. une demande depuis le formulaire général de contact ;
3. une candidature avec deux petits PDF de test ne contenant aucune donnée personnelle réelle.

Pour chaque essai, vérifier :

- le message de confirmation sur le site ;
- la réception dans `carriere@lasodapci.com` ;
- le sujet de l'email ;
- la présence de l'adresse du visiteur dans **Répondre à** ;
- l'ouverture du CV et de la lettre pour la candidature ;
- le dossier des courriers indésirables si le message n'apparaît pas dans la boîte principale.

Supprimer les pièces jointes de test après validation.

## 7. Comprendre les erreurs

- **422** : un champ ou un document est invalide. Corriger le contenu indiqué par le formulaire.
- **429** : trop de demandes ont été envoyées depuis la même connexion. Attendre avant de recommencer.
- **500** : la configuration privée, les droits du dossier, PHP ou le serveur SMTP doit être vérifié.

En cas d'erreur 500, consulter **Erreurs** dans cPanel ou le journal PHP. Le site enregistre seulement une référence technique et le type d'erreur ; il n'enregistre ni le mot de passe, ni le message du visiteur, ni les documents.

Ne jamais copier le mot de passe SMTP dans un ticket, une capture d'écran ou un journal partagé.
