# Contexte projet — Site web restaurant (Wagaweb)

## Vue d'ensemble
Ce projet est un site web pour un restaurant, composé de deux parties :
1. **Site public (vitrine)** : accueil, menu, réservation de table, commande en ligne
2. **Panneau d'administration** : interface où le restaurateur gère lui-même son menu — il crée ses propres catégories (ex: "Entrées", "Plats", "Spécialités du jour") et ses propres plats (nom, prix, photo, description, disponibilité), sans intervention technique de notre part après la livraison.

Le contenu du menu n'est JAMAIS codé en dur. Tout vient de la base de données MySQL, géré via le panneau admin.

## Stack technique
- **Backend** : PHP natif (pas de framework), avec PDO pour toutes les requêtes SQL
- **Base de données** : MySQL
- **Frontend** : HTML/CSS/JS classique, pas de framework JS (pas de React/Vue)
- **Hébergement cible** : Hostinger mutualisé — donc PAS de dépendances nécessitant Composer, Node.js, ou un accès serveur avancé. Le code doit tourner avec uniquement PHP + MySQL standards.

## Règles de sécurité non négociables
- TOUTES les requêtes SQL utilisent des requêtes préparées PDO (jamais de concaténation de variables dans le SQL)
- Tous les inputs utilisateurs sont validés et échappés avant affichage (protection XSS avec htmlspecialchars)
- Les mots de passe admin sont hashés avec password_hash() / vérifiés avec password_verify()
- Les uploads d'images (logo, photos de plats) sont validés par type MIME réel et taille, jamais par extension de fichier seule
- Aucun identifiant de connexion à la base de données en dur dans le code — tout passe par un fichier de config séparé, non versionné (config.php exclu du dépôt git via .gitignore)

## Structure de dossiers cible
```
/
├── config/
│   └── database.php       (connexion PDO, lit les identifiants depuis un fichier non versionné)
├── includes/
│   ├── functions.php      (fonctions réutilisables)
│   └── auth.php           (vérification de session admin)
├── public/                 (racine du site visible publiquement)
│   ├── index.php
│   ├── menu.php
│   ├── reservation.php
│   ├── commande.php
│   ├── css/
│   ├── js/
│   └── uploads/            (photos plats, logo — écriture contrôlée par l'admin uniquement)
├── admin/
│   ├── login.php
│   ├── dashboard.php
│   ├── categories.php
│   ├── plats.php
│   ├── reservations.php
│   └── commandes.php
├── database/
│   └── schema.sql          (script de création des tables, à exécuter une fois par client)
└── .gitignore
```

## Modèle de données (vue d'ensemble)
- `restaurant` : informations générales (nom, logo, couleur principale, horaires, adresse, téléphone)
- `categories` : catégories de menu définies par le restaurateur (nom, ordre d'affichage)
- `plats` : plats liés à une catégorie (nom, description, prix, photo, disponible oui/non)
- `reservations` : réservations de table (nom client, téléphone, date, heure, nombre de personnes, statut)
- `commandes` : commandes en ligne (statut, mode sur place/livraison, total)
- `commande_lignes` : détail des plats commandés par commande
- `admin_users` : comptes administrateurs du restaurant

## Conventions de code
- Noms de fichiers et variables en français autorisé pour rester cohérent avec le métier (ex: `$plats`, `categorie_id`), mais les noms de fonctions techniques génériques peuvent rester en anglais (ex: `connectDatabase()`)
- Indentation 4 espaces
- Chaque fichier PHP commence par les require/include nécessaires en haut, jamais au milieu du code
- Pas de logique métier (requêtes SQL complexes) directement mélangée avec le HTML — séparer le traitement des données de l'affichage autant que raisonnablement possible sans framework

## Ce qui n'est PAS dans le périmètre pour l'instant
- Paiement en ligne par carte bancaire (on commence avec "paiement à la livraison / sur place")
- Multi-langue
- Multi-restaurant sur une même base (chaque client a son propre site + sa propre base)
