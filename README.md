# Wagaweb — Site web restaurant

Site web pour restaurant : vitrine, commande en ligne, réservation. Menu et catégories entièrement gérés par le restaurateur via un panneau admin.

## Stack technique

- **Backend** : PHP natif + PDO (pas de framework)
- **Base de données** : MySQL (InnoDB, utf8mb4)
- **Frontend** : HTML / CSS / JS vanilla
- **Hébergement cible** : Hostinger mutualisé (PHP + MySQL standards, sans Composer ni Node.js)

## Installation locale

### 1. Base de données

Créez une base de données MySQL puis exécutez le script de création des tables :

```bash
mysql -u root -p nom_de_votre_base < database/schema.sql
```

Ou importez `database/schema.sql` via phpMyAdmin.

### 2. Configuration

Copiez le fichier d'exemple et renseignez vos identifiants locaux :

```bash
cp config/database.example.php config/database.local.php
```

Éditez `config/database.local.php` avec vos valeurs réelles (ce fichier est exclu du dépôt git).

### 3. Serveur local

Pointez la racine de votre serveur web (Apache/XAMPP) vers ce dossier.  
Exemple avec XAMPP : configurez un virtual host vers `c:/xampp/htdocs/site`.

### 4. Premier compte administrateur

Visitez `/admin/inscription.php` pour créer le compte administrateur.  
Cette page se désactive automatiquement une fois le compte créé.

## Structure du projet

```
/
├── config/          Connexion PDO (database.local.php non versionné)
├── includes/        Fonctions partagées (auth, helpers)
├── public/          Fichiers publics (CSS, JS, uploads)
├── admin/           Panneau d'administration
└── database/        Schéma SQL (à exécuter une fois à l'installation)
```

## Sécurité

- Toutes les requêtes SQL utilisent des requêtes préparées PDO
- Mots de passe hashés avec `password_hash()` (bcrypt)
- Protection CSRF sur tous les formulaires
- Uploads validés par type MIME réel
- `config/database.local.php` exclu du dépôt (ne jamais commiter des identifiants réels)
