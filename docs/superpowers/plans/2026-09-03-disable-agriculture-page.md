# Désactivation temporaire de la page Agriculture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire en sorte que `/agriculture.html` soit absente du site et renvoie la page 404 de l’hébergement statique.

**Architecture:** Le site reste statique. La page agricole sera supprimée ; les scripts qui traitent cette page comme une page existante seront nettoyés, tandis que les contenus agricoles de l’accueil et des autres pages resteront inchangés.

**Tech Stack:** HTML statique, JavaScript vanilla, PowerShell, npm.

---

### Task 1: Retirer la page et ses références opérationnelles

**Files:**
- Delete: `agriculture.html`
- Modify: `js/tailwind-main.js:121-126`
- Modify: `js/tailwind-secondary.js:118-123`
- Modify: `tools/export-site-captures.ps1:75-80`

- [ ] **Step 1: Supprimer `agriculture.html`**

Supprimer uniquement le fichier `agriculture.html`. Ne pas supprimer le dossier `img/agriculture/`, car ses images sont encore utilisées par `index.html` et `reboisement.html`.

- [ ] **Step 2: Retirer `agriculture.html` des ensembles de pages actives**

Dans les deux fichiers JavaScript, retirer uniquement la ligne `"agriculture.html"` des ensembles `servicePages`. Ne modifier aucune autre logique de navigation.

Le bloc `servicePages` doit passer de :

```javascript
const servicePages = new Set([
  "service.html",
  "rehabilitation.html",
  "elevage.html",
  "agriculture.html"
]);
```

à :

```javascript
const servicePages = new Set([
  "service.html",
  "rehabilitation.html",
  "elevage.html"
]);
```

- [ ] **Step 3: Retirer la page du script de captures**

Dans `tools/export-site-captures.ps1`, retirer uniquement l’entrée `"agriculture.html"` de la liste des pages à capturer.

La liste doit conserver les entrées voisines dans cet ordre :

```powershell
    "reboisement.html",
    "elevage.html",
    "pisciculture.html",
```

- [ ] **Step 4: Vérifier les références restantes**

Run: `rg -n --hidden -S 'agriculture\.html' .`

Expected: aucune référence dans les fichiers opérationnels ; les éventuelles occurrences dans la documentation historique sont acceptables et ne doivent pas recréer la page.

- [ ] **Step 5: Commit ciblé**

```powershell
git add -u agriculture.html js/tailwind-main.js js/tailwind-secondary.js tools/export-site-captures.ps1
git commit -m "chore: disable agriculture page temporarily"
```

### Task 2: Vérifier la réponse 404 et la non-régression

**Files:**
- Test: `404.html`, fichiers modifiés de la Task 1

- [ ] **Step 1: Vérifier que le fichier est absent**

Run: `Test-Path agriculture.html`

Expected: `False`.

- [ ] **Step 2: Lancer les vérifications du projet**

Run: `npm run check`

Expected: commande terminée avec succès.

- [ ] **Step 3: Tester le statut HTTP avec un serveur statique local**

Lancer temporairement un serveur statique depuis la racine du projet, puis demander `/agriculture.html` avec un client HTTP. Vérifier que le statut est `404` et que le contenu retourné correspond à `404.html`. Arrêter ensuite le serveur temporaire.

- [ ] **Step 4: Vérifier l’état Git final**

Run: `git status --short`

Expected: seuls les changements de cette tâche sont ajoutés au commit ; les modifications préexistantes de l’utilisateur restent intactes et non incluses.
