-- ============================================================
-- Migration 002 : Ajout du champ whatsapp_numero à la table restaurant
--
-- Objectif : stocker le numéro WhatsApp du restaurant pour le
-- Flux A de notification (restaurateur → son propre numéro /
-- son équipe), via un lien wa.me pré-rempli cliqué manuellement.
-- Ce n'est PAS un envoi automatique : le restaurateur doit cliquer
-- le lien et appuyer sur Envoyer dans WhatsApp.
--
-- Format attendu : chiffres uniquement, format international
-- SANS le + ni les espaces. Exemples :
--   22670000000  (Burkina Faso, indicatif 226)
--   33612345678  (France, indicatif 33)
--
-- Rollback manuel si nécessaire :
--   ALTER TABLE `restaurant` DROP COLUMN `whatsapp_numero`;
--
-- Note DDL MySQL : ALTER TABLE est une instruction DDL qui
-- génère un commit implicite — elle ne peut pas être annulée
-- par ROLLBACK. Vérifiez le résultat avec un SELECT avant
-- d'exécuter d'autres instructions dépendantes.
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE `restaurant`
    ADD COLUMN `whatsapp_numero` VARCHAR(20) NULL DEFAULT NULL
        COMMENT 'Numéro WhatsApp du restaurant au format international sans + (ex: 22670000000). Utilisé pour les liens wa.me du Flux A (notification interne).'
    AFTER `telephone`;
