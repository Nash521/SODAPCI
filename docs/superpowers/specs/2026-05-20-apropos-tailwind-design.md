# Design de la page A propos

## Objectif

Refaire completement `apropos.html` avec Tailwind CSS pour obtenir une page simple, moderne, lisible et credible, coherente avec la migration deja engagee sur `index.html` et `service.html`.

## Direction retenue

Approche institutionnelle editoriale.

La page doit inspirer confiance, presenter clairement le role de SODAP-CI et supprimer l'effet "template en vrac" encore present dans la version actuelle.

## Structure de page

La page contient uniquement trois sections principales :

1. Hero / presentation de l'entreprise
2. Mission et vision
3. Valeurs

Les anciennes sections de template non pertinentes sont supprimees completement.

## Hero

### Contenu

- Badge : `Entreprise agropastorale & environnementale`
- Titre : `Redonner vie aux terres, construire un avenir durable`
- Texte :
  - `SODAP-CI SARL est une entreprise ivoirienne specialisee dans la rehabilitation des zones degradees par l'exploitation miniere, l'agriculture durable et les activites agropastorales.`
  - `Nous accompagnons la transformation des terres exploitees en espaces productifs et utiles, tout en contribuant au developpement economique et social des communautes locales.`
  - `Grace a une approche alliant expertise technique, innovation et responsabilite environnementale, nous developpons des solutions durables adaptees aux realites du terrain en Cote d'Ivoire.`
- Boutons :
  - `Decouvrir nos services`
  - `Nous contacter`

### Traitement visuel

- Grand visuel de fond avec overlay sombre pour garder une excellente lisibilite.
- Composition aeree avec largeur de lecture controlee.
- CTA primaires visibles des l'arrivee sur la page.

### Image retenue

- Hero : `img/carousel-2.JPG`

## Mission et vision

### Structure

- Section sur fond clair.
- Deux cartes ou colonnes modernes :
  - `Notre mission`
  - `Notre vision`
- Un petit bloc complementaire avec trois marqueurs :
  - `Environnement`
  - `Agriculture durable`
  - `Innovation terrain`

### Contenu

#### Notre mission

`Restaurer les terres degradees tout en developpant des activites agricoles et agropastorales durables, capables de creer de la valeur economique, environnementale et sociale.`

`Nous croyons qu'une terre exploitee peut retrouver son potentiel grace a des solutions responsables, innovantes et adaptees aux besoins des communautes.`

#### Notre vision

`Devenir une reference en Cote d'Ivoire dans la rehabilitation environnementale et les solutions agropastorales integrees.`

`Notre ambition est de batir un modele durable ou developpement economique, protection de l'environnement et impact social evoluent ensemble pour les generations futures.`

### Image retenue

- Accompagnement de section : `img/about.JPG`

## Valeurs

### Titre

`Les valeurs qui guident nos actions`

### Introduction

`Chez SODAP-CI SARL, nos engagements reposent sur des valeurs fortes qui orientent chacune de nos interventions et nos relations avec nos partenaires et communautes.`

### Cartes de valeurs

1. `Responsabilite environnementale`
2. `Innovation`
3. `Qualite`
4. `Integrite`
5. `Engagement communautaire`

Chaque valeur est presentee dans une carte Tailwind avec icone, titre et texte court.

## Contraintes de design

- Reutiliser les reperes visuels deja presents sur les pages Tailwind du site.
- Garder la topbar, la navigation sticky et le footer dans une version coherente avec le reste du projet.
- Eviter les blocs trop denses ou les sections template sans rapport avec SODAP-CI.
- Assurer une lecture fluide sur mobile, tablette et desktop.
- Conserver une tonalite institutionnelle, serieuse et professionnelle.

## Responsive

- Hero empile proprement le texte et les boutons sur mobile.
- Mission et vision passent de une colonne a deux colonnes a partir du desktop.
- Les cartes de valeurs s'adaptent en grille responsive.

## Verification attendue

- `apropos.html` n'affiche plus de sections du template d'origine non pertinentes.
- La page est entierement reformatee avec Tailwind.
- Les textes fournis par l'utilisateur sont integres correctement.
- Les images choisies dans `img/` sont coherentes avec chaque section.
- Le rendu reste lisible et solide sur mobile et desktop.
