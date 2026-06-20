<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Retourne les commandes d'un restaurant pour une date donnée (basé sur created_at),
 * triées par heure décroissante (la plus récente en premier).
 *
 * Choix du tri décroissant — différence intentionnelle avec les réservations :
 *   Les réservations sont un planning à lire chronologiquement (12h → 23h) : le
 *   restaurateur consulte son service dans l'ordre d'arrivée des clients.
 *   Les commandes sont un flux opérationnel en temps réel : la plus récente est
 *   la plus urgente et doit apparaître en tête, comme un ticket de cuisine fraîchement
 *   imprimé. Les deux tables représentent des objets métier différents.
 *
 * Choix de 2 requêtes séparées plutôt qu'un JOIN :
 *   Un JOIN `commandes LEFT JOIN commande_lignes` produirait N lignes résultat par
 *   commande (une par ligne de commande), obligeant PHP à déduplicer toutes les colonnes
 *   de la commande répétées N fois. Avec 2 requêtes :
 *     1. Commandes du jour → liste propre sans duplication.
 *     2. Lignes de ces commandes via IN (...) → indexées par commande_id en mémoire.
 *   L'assemblage en PHP est O(n) sur le total de lignes. Le surcoût réseau d'une 2e
 *   requête est négligeable pour un restaurant local (< 100 commandes/jour en pratique).
 *
 * @param int    $restaurantId
 * @param string $date  Format YYYY-MM-DD (validé par l'appelant)
 * @return array  Tableau de commandes, chacune avec une clé 'lignes' (tableau associatif)
 */
function getCommandesByDate(int $restaurantId, string $date): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }

    try {
        $db = getDB();

        // Requête 1 : commandes du jour, plus récentes en premier
        $stmt = $db->prepare(
            'SELECT id, nom_client, telephone_client, mode, adresse_livraison,
                    statut, total, created_at
             FROM commandes
             WHERE restaurant_id = ? AND DATE(created_at) = ?
             ORDER BY created_at DESC'
        );
        $stmt->execute([$restaurantId, $date]);
        $commandes = $stmt->fetchAll();

        if (empty($commandes)) {
            return [];
        }

        // Requête 2 : lignes de toutes ces commandes en une seule passe
        // Les IDs viennent de la BD (résultat de la requête 1), pas d'une saisie
        // utilisateur : le cast en int dans array_column suffit à exclure toute injection.
        $ids          = array_map('intval', array_column($commandes, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmtLignes = $db->prepare(
            "SELECT cl.id, cl.commande_id, cl.quantite, cl.prix_unitaire, cl.sous_total,
                    p.nom AS plat_nom
             FROM commande_lignes cl
             INNER JOIN plats p ON p.id = cl.plat_id
             WHERE cl.commande_id IN ($placeholders)
             ORDER BY cl.id ASC"
        );
        $stmtLignes->execute($ids);
        $toutesLignes = $stmtLignes->fetchAll();

        // Indexe les lignes par commande_id — assemblage O(n) sans boucle imbriquée
        $lignesParCommande = [];
        foreach ($toutesLignes as $ligne) {
            $lignesParCommande[(int) $ligne['commande_id']][] = $ligne;
        }

        foreach ($commandes as &$commande) {
            $commande['lignes'] = $lignesParCommande[(int) $commande['id']] ?? [];
        }
        unset($commande);

        return $commandes;

    } catch (Exception $e) {
        error_log('[commandes] getCommandesByDate : ' . $e->getMessage());
        return [];
    }
}

/**
 * Met à jour le statut d'une commande en vérifiant l'appartenance au restaurant.
 *
 * La clause WHERE id = ? AND restaurant_id = ? garantit qu'un admin ne peut pas
 * modifier une commande d'un autre restaurant, même en devinant l'id.
 * Tous les statuts sont librement accessibles sans ordre imposé (pas de machine d'état).
 *
 * @param int    $commandeId
 * @param int    $restaurantId  Garde d'appartenance — vérifié en BD
 * @param string $nouveauStatut Doit être l'une des six valeurs de l'enum
 * @return bool  true si une ligne a été modifiée, false sinon
 */
function updateCommandeStatut(int $commandeId, int $restaurantId, string $nouveauStatut): bool
{
    $statutsValides = ['en_attente', 'confirmee', 'en_preparation', 'prete', 'livree', 'annulee'];
    if (!in_array($nouveauStatut, $statutsValides, true)) {
        return false;
    }

    try {
        $stmt = getDB()->prepare(
            'UPDATE commandes
             SET statut = ?
             WHERE id = ? AND restaurant_id = ?'
        );
        $stmt->execute([$nouveauStatut, $commandeId, $restaurantId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('[commandes] updateCommandeStatut : ' . $e->getMessage());
        return false;
    }
}
