 # Désactivation temporaire de la page Agriculture

 ## Objectif

 Rendre `agriculture.html` indisponible temporairement afin qu’une requête directe vers cette URL reçoive une réponse 404 de l’hébergement statique.

 ## Décision

 Supprimer le fichier `agriculture.html`. Dans la configuration actuelle, le projet est composé de fichiers HTML statiques et ne contient pas de routeur applicatif : l’absence du fichier permet donc à l’hébergeur de traiter l’URL comme une ressource inexistante et d’utiliser `404.html`.

 Les contenus et images agricoles utilisés par l’accueil ou les autres pages restent inchangés. Les références techniques de suivi/export qui ciblent explicitement cette page seront également retirées afin de ne pas tenter de capturer une page volontairement absente. Les scripts de navigation ne considéreront plus cette URL comme une page active.

 ## Critères de réussite

 - `agriculture.html` n’existe plus dans le site publié.
 - Une requête vers `/agriculture.html` retourne un statut HTTP 404 et affiche la page 404 du site.
 - Les autres pages et leurs contenus agricoles fonctionnent toujours.
 - Les vérifications existantes du projet passent.

 ## Vérification

 Vérifier l’absence du fichier et des références opérationnelles à la page, lancer `npm run check`, puis tester localement le statut HTTP si un serveur statique est disponible.
