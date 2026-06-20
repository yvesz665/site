# Guide de contribution

## ⚠️ Ce dépôt est PUBLIC

Avant chaque commit, vérifier impérativement qu'aucune donnée réelle de client n'est incluse :
- Pas de vrais identifiants de base de données
- Pas de vraie adresse email, numéro de téléphone ou nom de restaurant réel dans le code
- Pas de fichier `config/database.local.php` (exclu par `.gitignore`, ne jamais forcer son ajout)
- Pas d'image uploadée par un client réel dans `public/uploads/`

## Format des messages de commit

Utiliser le format : `type: description courte en français`

### Types disponibles

| Type | Quand l'utiliser |
|---|---|
| `feat` | Nouvelle fonctionnalité (ex: page menu, gestion catégories) |
| `fix` | Correction d'un bug |
| `style` | Modification CSS, mise en page (sans changement de comportement) |
| `refactor` | Réécriture de code sans changer la fonctionnalité |
| `security` | Correction d'une faille de sécurité |
| `db` | Modification du schéma SQL |
| `docs` | Modification de documentation uniquement |
| `chore` | Tâche technique (config, .gitignore, dépendances) |

### Exemples concrets

```
feat: ajout page menu public avec catégories et plats
feat: gestion des catégories dans le panneau admin
fix: correction redirection après déconnexion
style: mise en page formulaire de réservation
security: validation MIME sur upload photo de plat
db: ajout colonne ordre_affichage sur table categories
docs: mise à jour README installation locale
chore: ajout .env au .gitignore
```

## Workflow de branches

- **`develop`** : branche de travail quotidien — tous les commits vont ici
- **`main`** : branche stable/livrable — on ne pousse ici que quand une fonctionnalité est terminée et testée

```bash
# Travailler sur develop (par défaut)
git checkout develop
# ... modifications ...
git add fichier.php
git commit -m "feat: description"
git push

# Livrer vers main quand c'est prêt
git checkout main
git merge develop
git push
git checkout develop
```
