# Migration Tailwind CSS locale

## Objectif

Migrer progressivement le site statique SODAP-CI de Bootstrap vers Tailwind CSS avec une installation locale propre. La migration commence par `index.html` comme page pilote, afin de valider le rendu, le responsive et les interactions avant d'appliquer le modèle aux autres pages.

## Contexte actuel

Le projet est un site HTML statique issu d'un template HTML Codex. Les pages utilisent actuellement :

- `css/bootstrap.min.css` pour la grille, les utilitaires, les boutons, la navbar, les formulaires et le carousel.
- `css/style.css` pour les styles du template et la personnalisation SODAP-CI.
- Bootstrap JS via CDN pour `collapse`, `dropdown`, `carousel` et certains événements de modal.
- Des librairies conservables pendant la migration : jQuery, WOW, CounterUp, Waypoints, Owl Carousel, Font Awesome et Bootstrap Icons.

Le dépôt contient déjà des modifications locales sur les pages HTML. La migration doit donc éviter toute réinitialisation ou écrasement global.

## Approche retenue

Utiliser une migration progressive avec Tailwind local :

1. Installer Tailwind via `npm`.
2. Ajouter une configuration Tailwind adaptée au site statique.
3. Créer un fichier source CSS, par exemple `src/input.css`.
4. Compiler Tailwind vers un fichier servi par le site, par exemple `css/tailwind.css`.
5. Migrer uniquement `index.html` en premier.
6. Remplacer les dépendances Bootstrap de la page pilote par des classes Tailwind et du JavaScript léger lorsque nécessaire.
7. Valider le résultat avant d'étendre la migration aux autres pages.

## Architecture cible

Fichiers attendus :

- `package.json` : scripts de build Tailwind.
- `tailwind.config.js` : contenu scanné dans les fichiers HTML et JS, thème SODAP-CI.
- `src/input.css` : directives Tailwind et éventuelles couches de composants.
- `css/tailwind.css` : fichier compilé utilisé par les pages.

Le fichier `css/style.css` peut être conservé temporairement pour les styles spécifiques du template qui ne sont pas encore migrés. Les styles Bootstrap ne doivent plus être nécessaires sur la page pilote une fois la migration de `index.html` terminée.

## Design Tailwind

Le thème Tailwind doit reprendre les repères visuels existants :

- Couleur principale : vert SODAP-CI proche de `#214f21`.
- Couleur hover principale : `#1a3e1a`.
- Espacements équivalents aux sections actuelles `pt-6`, `pb-6`, `mt-6`, `mb-6`.
- Conteneurs centrés avec largeur maximale cohérente avec Bootstrap.
- Typographie lisible, professionnelle et adaptée à un site institutionnel.

Des classes de composants Tailwind peuvent être créées avec `@layer components` pour les éléments répétés : boutons, boutons carrés, conteneurs, liens de navigation, cartes de services, sections et champs de formulaire.

## Composants de la page pilote

`index.html` doit couvrir les composants les plus représentatifs :

- Loader initial.
- Topbar.
- Navbar sticky avec menu mobile.
- Dropdown "Projets".
- Hero / carousel d'accueil.
- Sections à grille responsive.
- Cartes d'activités/services.
- Formulaires ou champs de newsletter si présents.
- Footer.
- Bouton retour en haut.

## Interactions

Les interactions Bootstrap utilisées par `index.html` doivent être remplacées ou adaptées :

- Menu mobile : petit script JavaScript pour ouvrir/fermer la navigation.
- Dropdown projets : comportement hover sur desktop et clic sur mobile.
- Carousel hero : remplacement par une implémentation JavaScript légère locale, sans Bootstrap JS.
- Bouton retour en haut, animations WOW et Owl Carousel peuvent rester tant qu'ils ne dépendent pas de Bootstrap CSS/JS.

## Compatibilité avec les autres pages

Les autres pages ne sont pas migrées dans la première phase. Elles peuvent continuer à utiliser Bootstrap jusqu'à validation du pilote. Après validation, le même modèle sera appliqué page par page pour éviter une régression massive.

## Vérification

La page pilote devra être vérifiée avec :

- Build Tailwind local réussi.
- Absence de référence Bootstrap CSS/JS dans `index.html` migré.
- Navigation desktop fonctionnelle.
- Menu mobile fonctionnel.
- Dropdown projets fonctionnel.
- Hero visible et utilisable.
- Sections lisibles sur mobile, tablette et desktop.
- Pas de texte qui déborde de son conteneur.
- Pas de chevauchement incohérent entre éléments.

## Hors périmètre de la première phase

- Migration immédiate de toutes les pages.
- Refactor complet de tout `css/style.css`.
- Suppression globale du dossier `scss/bootstrap`.
- Changement du contenu éditorial ou des images du site.
- Refonte complète de l'identité visuelle.

## Critère de validation

La phase pilote est réussie lorsque `index.html` fonctionne sans Bootstrap CSS/JS, conserve l'identité visuelle SODAP-CI, reste responsive et peut servir de modèle fiable pour migrer les autres pages.
