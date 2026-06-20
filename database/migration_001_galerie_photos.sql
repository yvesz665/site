-- ============================================================
-- Migration 001 : Galerie de photos par plat
-- Remplace la colonne photo_path unique sur la table plats
-- par une table plat_photos permettant plusieurs photos par plat.
--
-- À exécuter sur une base déjà créée avec l'ancien schéma.
-- Pour une nouvelle installation, utiliser schema.sql directement.
--
-- AVERTISSEMENT — Limite MySQL sur les transactions DDL :
-- MySQL valide automatiquement (auto-commit) les instructions DDL
-- (CREATE TABLE, ALTER TABLE DROP COLUMN). Contrairement à PostgreSQL,
-- ces opérations NE PEUVENT PAS être annulées par un ROLLBACK.
-- Seule l'étape 2 (INSERT de migration de données) est réellement
-- transactionnelle. En cas d'erreur après l'étape 1 mais avant
-- l'étape 3, voir le rollback manuel ci-dessous.
--
-- Rollback manuel si nécessaire :
--   -- Supprimer la table créée à l'étape 1 :
--   DROP TABLE IF EXISTS plat_photos;
--   -- Restaurer la colonne supprimée à l'étape 3 (si déjà exécutée) :
--   ALTER TABLE plats ADD COLUMN photo_path VARCHAR(500) NULL DEFAULT NULL AFTER prix;
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- Étape 1 : Création de la table plat_photos
-- (DDL — validé automatiquement par MySQL, non annulable)
-- ============================================================

CREATE TABLE IF NOT EXISTS `plat_photos` (
    `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    `plat_id`         INT UNSIGNED      NOT NULL,
    `photo_path`      VARCHAR(500)      NOT NULL,
    `ordre_affichage` SMALLINT UNSIGNED NOT NULL DEFAULT 0
                          COMMENT 'Ordre dans la galerie — valeur la plus basse = photo principale',
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_plat_photos_plat`
        FOREIGN KEY (`plat_id`) REFERENCES `plats` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX `idx_plat_photos_plat_ordre` (`plat_id`, `ordre_affichage`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- Étape 2 : Migration des données existantes
-- (DML — transactionnel : annulable par ROLLBACK si nécessaire)
-- Chaque plat ayant un photo_path non vide devient une ligne
-- dans plat_photos avec ordre_affichage = 1.
-- ============================================================

START TRANSACTION;

INSERT INTO `plat_photos` (`plat_id`, `photo_path`, `ordre_affichage`, `created_at`)
SELECT
    `id`,
    `photo_path`,
    1,
    `created_at`
FROM `plats`
WHERE `photo_path` IS NOT NULL
  AND `photo_path` <> '';

COMMIT;


-- ============================================================
-- Étape 3 : Suppression de la colonne photo_path sur plats
-- (DDL — validé automatiquement par MySQL, non annulable)
-- À n'exécuter qu'après avoir vérifié que l'étape 2 s'est
-- bien déroulée (contrôle : SELECT COUNT(*) FROM plat_photos).
-- ============================================================

ALTER TABLE `plats` DROP COLUMN `photo_path`;
