-- ============================================================
-- Schéma de base de données — Site Restaurant (Wagaweb)
-- Engine : InnoDB | Charset : utf8mb4_unicode_ci
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Table : restaurant
-- Une ligne par client (chaque restaurant a sa propre instance)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `restaurant` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nom`                VARCHAR(255) NOT NULL,
    `logo_path`          VARCHAR(500)     NULL DEFAULT NULL,
    `couleur_principale` CHAR(7)      NOT NULL DEFAULT '#FF6B35'
                             COMMENT 'Code hexadécimal (#RRGGBB)',
    `adresse`            VARCHAR(500)     NULL DEFAULT NULL,
    `telephone`          VARCHAR(20)      NULL DEFAULT NULL,
    `whatsapp_numero`    VARCHAR(20)      NULL DEFAULT NULL
                             COMMENT 'Numéro WhatsApp au format international sans + (ex: 22670000000). Utilisé pour les liens wa.me du Flux A (notification interne réservation).',
    `email`              VARCHAR(255)     NULL DEFAULT NULL,
    -- Choix JSON pour horaires : permet une représentation structurée par jour
    -- ex: {"lundi":{"ouvert":true,"debut":"11:30","fin":"22:00"}, "mardi":{...}, ...}
    -- Avantage vs TEXT libre : le frontend peut lire/afficher "Ouvert aujourd'hui" sans
    -- parser une chaîne arbitraire. MySQL 5.7+ supporte le type JSON nativement.
    `horaires`           JSON             NULL DEFAULT NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : categories
-- Catégories de menu définies librement par le restaurateur
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categories` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `restaurant_id`   INT UNSIGNED     NOT NULL,
    `nom`             VARCHAR(255)     NOT NULL,
    `ordre_affichage` SMALLINT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'Tri croissant — le restaurateur peut réordonner ses catégories',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ON DELETE RESTRICT : on refuse la suppression du restaurant si des catégories existent.
    -- Le restaurateur doit d'abord vider son menu via l'interface admin avant de supprimer
    -- le compte, ce qui évite des suppressions en cascade accidentelles.
    CONSTRAINT `fk_categories_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_categories_restaurant`    (`restaurant_id`),
    INDEX `idx_categories_ordre`         (`restaurant_id`, `ordre_affichage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : plats
-- Plats liés à une catégorie
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plats` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `categorie_id` INT UNSIGNED  NOT NULL,
    `nom`          VARCHAR(255)  NOT NULL,
    `description`  TEXT              NULL DEFAULT NULL,
    -- DECIMAL(8,2) : évite les erreurs d'arrondi flottant sur les prix.
    -- Supporte jusqu'à 999 999,99 — largement suffisant.
    `prix`         DECIMAL(8,2)  NOT NULL,
    -- Pas de colonne photo_path ici : les photos sont gérées dans la table plat_photos
    -- (galerie multi-photos). Pour afficher une vignette dans une liste, le code applicatif
    -- récupère la première ligne de plat_photos ORDER BY ordre_affichage ASC LIMIT 1.
    -- Ce choix évite de maintenir deux systèmes redondants (colonne unique + galerie).
    `disponible`   TINYINT(1)    NOT NULL DEFAULT 1
                       COMMENT '1 = disponible à la commande, 0 = masqué sur le menu public',
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ON DELETE RESTRICT : on refuse la suppression d'une catégorie tant qu'elle contient
    -- des plats, qu'ils aient été commandés ou non.
    -- Cohérence avec la politique générale : on ne supprime jamais physiquement un plat,
    -- on le désactive via disponible=0. Le restaurateur doit donc vider une catégorie
    -- (désactiver ou déplacer ses plats) avant de pouvoir la supprimer.
    -- Évite aussi l'erreur MySQL confuse qui surviendrait si un CASCADE ici se heurtait
    -- au RESTRICT de fk_lignes_plat pour un plat déjà commandé.
    CONSTRAINT `fk_plats_categorie`
        FOREIGN KEY (`categorie_id`) REFERENCES `categories` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    -- Index composite categorie+disponibilite : requête typique du menu public
    -- "SELECT * FROM plats WHERE categorie_id = ? AND disponible = 1"
    INDEX `idx_plats_categorie_dispo` (`categorie_id`, `disponible`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : plat_photos
-- Galerie de photos par plat (remplace la colonne photo_path unique).
-- La première photo (ordre_affichage = valeur la plus basse) sert
-- de photo principale / vignette dans les listes du menu public.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `plat_photos` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `plat_id`         INT UNSIGNED     NOT NULL,
    `photo_path`      VARCHAR(500)     NOT NULL,
    `ordre_affichage` SMALLINT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'Ordre dans la galerie — la valeur la plus basse = photo principale',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ON DELETE CASCADE : si le plat est supprimé, ses photos n'ont plus de sens.
    -- Ce CASCADE ne contredit pas les RESTRICT existants dans la chaîne :
    --   • plats→categories RESTRICT bloque la suppression d'une catégorie non vide
    --     (ne concerne pas la suppression d'un plat).
    --   • commande_lignes→plats RESTRICT bloque la suppression d'un plat commandé.
    -- Si ce dernier RESTRICT s'oppose à la suppression du plat, ce CASCADE ne
    -- s'exécute jamais. Si la suppression du plat réussit (aucune commande liée),
    -- les photos sont supprimées avec lui — comportement voulu.
    CONSTRAINT `fk_plat_photos_plat`
        FOREIGN KEY (`plat_id`) REFERENCES `plats` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    -- Index composite (plat_id, ordre_affichage) : utilisé par la requête
    -- "SELECT * FROM plat_photos WHERE plat_id = ? ORDER BY ordre_affichage ASC"
    -- aussi bien pour afficher la galerie complète que pour récupérer la vignette.
    INDEX `idx_plat_photos_plat_ordre` (`plat_id`, `ordre_affichage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : reservations
-- Réservations de table effectuées par les clients
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reservations` (
    `id`                INT UNSIGNED          NOT NULL AUTO_INCREMENT,
    `restaurant_id`     INT UNSIGNED          NOT NULL,
    `nom_client`        VARCHAR(255)          NOT NULL,
    `telephone_client`  VARCHAR(20)           NOT NULL,
    `date_reservation`  DATE                  NOT NULL,
    `heure_reservation` TIME                  NOT NULL,
    `nombre_personnes`  TINYINT UNSIGNED      NOT NULL DEFAULT 1,
    `statut`            ENUM('en_attente','confirmee','annulee')
                            NOT NULL DEFAULT 'en_attente',
    `notes`             TEXT                      NULL DEFAULT NULL,
    `created_at`        DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ON DELETE CASCADE : si le restaurant est supprimé, ses réservations n'ont plus
    -- de contexte et peuvent être supprimées avec lui.
    CONSTRAINT `fk_reservations_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_reservations_restaurant` (`restaurant_id`),
    -- Index composite (date, heure) : requête de vérification de disponibilité typique
    -- "WHERE restaurant_id = ? AND date_reservation = ? AND heure_reservation BETWEEN ? AND ?"
    INDEX `idx_reservations_date_heure` (`date_reservation`, `heure_reservation`),
    INDEX `idx_reservations_statut`     (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : commandes
-- Commandes passées par les clients (sur place ou livraison)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commandes` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id`     INT UNSIGNED NOT NULL,
    `nom_client`        VARCHAR(255) NOT NULL,
    `telephone_client`  VARCHAR(20)  NOT NULL,
    `mode`              ENUM('sur_place','livraison') NOT NULL DEFAULT 'sur_place',
    `adresse_livraison` VARCHAR(500)     NULL DEFAULT NULL
                            COMMENT 'Obligatoire si mode=livraison, NULL si sur_place',
    `statut`            ENUM('en_attente','confirmee','en_preparation','prete','livree','annulee')
                            NOT NULL DEFAULT 'en_attente',
    -- DECIMAL(10,2) : total peut dépasser 999,99 pour de grands groupes
    `total`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- ON DELETE CASCADE : si le restaurant est supprimé, ses commandes vont avec.
    CONSTRAINT `fk_commandes_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_commandes_restaurant` (`restaurant_id`),
    INDEX `idx_commandes_statut`     (`statut`),
    INDEX `idx_commandes_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : commande_lignes
-- Détail des plats par commande
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `commande_lignes` (
    `id`           INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `commande_id`  INT UNSIGNED     NOT NULL,
    `plat_id`      INT UNSIGNED     NOT NULL,
    `quantite`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    -- SNAPSHOT du prix au moment de la commande.
    -- Le restaurateur peut modifier le prix d'un plat à tout moment.
    -- Sans ce champ, recalculer le total d'une commande passée donnerait un résultat
    -- faux (nouveau prix × ancienne quantité). Ce champ est intentionnellement
    -- dénormalisé pour garantir l'intégrité historique de chaque commande.
    `prix_unitaire` DECIMAL(8,2)   NOT NULL,
    -- sous_total = quantite × prix_unitaire, stocké pour éviter les recalculs à
    -- chaque affichage et pour garder une trace exacte même si la logique de calcul change.
    `sous_total`    DECIMAL(10,2)  NOT NULL,
    PRIMARY KEY (`id`),
    -- ON DELETE CASCADE : supprimer une commande supprime toutes ses lignes.
    -- Une ligne de commande sans commande parente n'a aucun sens.
    CONSTRAINT `fk_lignes_commande`
        FOREIGN KEY (`commande_id`) REFERENCES `commandes` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    -- ON DELETE RESTRICT : on refuse la suppression d'un plat référencé dans des commandes.
    -- Même si le plat disparaît de la carte, l'historique des commandes doit rester cohérent
    -- (nom du plat accessible, prix snapshot déjà stocké).
    -- Bonne pratique : le restaurateur marque un plat "indisponible" plutôt que de le supprimer.
    CONSTRAINT `fk_lignes_plat`
        FOREIGN KEY (`plat_id`) REFERENCES `plats` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    INDEX `idx_lignes_commande` (`commande_id`),
    INDEX `idx_lignes_plat`     (`plat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ------------------------------------------------------------
-- Table : admin_users
-- Comptes administrateurs liés à un restaurant
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `restaurant_id`      INT UNSIGNED NOT NULL,
    `email`              VARCHAR(255) NOT NULL,
    -- Jamais de mot de passe en clair.
    -- Ce champ stocke le hash produit par password_hash() en PHP (bcrypt par défaut).
    -- La vérification se fait avec password_verify($saisi, $hash_stocké).
    -- VARCHAR(255) est suffisant pour les algorithmes courants (bcrypt = 60 chars,
    -- argon2id = ~96 chars) et laisse de la marge pour de futurs algorithmes.
    `mot_de_passe_hash`  VARCHAR(255) NOT NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `derniere_connexion` DATETIME         NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    -- Unicité de l'email globale (un email ne peut pas être admin de deux restaurants)
    UNIQUE KEY `uk_admin_email` (`email`),
    -- ON DELETE CASCADE : si le restaurant est supprimé, ses administrateurs vont avec.
    CONSTRAINT `fk_admin_restaurant`
        FOREIGN KEY (`restaurant_id`) REFERENCES `restaurant` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_admin_restaurant` (`restaurant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;
