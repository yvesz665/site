<?php
require_once __DIR__ . '/../config/database.php';
// waNormaliserNumero() et waFormatDateFr() sont définis dans reservations_functions.php.
// On l'inclut ici pour éviter toute duplication de code entre les deux modules WhatsApp.
// require_once est idempotent : si la page inclut déjà ce fichier par ailleurs, aucun effet.
require_once __DIR__ . '/reservations_functions.php';

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

// ------------------------------------------------------------
// Flux 1 commandes — Client → Restaurant, déclenché côté public
// ------------------------------------------------------------

/**
 * Construit un lien wa.me pour que le CLIENT envoie un message WhatsApp
 * au restaurant juste après avoir validé sa commande en ligne (Flux 1 commandes).
 *
 * CONTEXTE D'UTILISATION — implémentation à venir, pas encore réalisée :
 *   Cette fonction sera appelée depuis la page publique de commande
 *   (public/commande.php, à créer), juste après l'insertion réussie de la commande
 *   en base de données (INSERT dans commandes + commande_lignes).
 *
 *   Mécanisme prévu côté serveur + navigateur :
 *     1. Le formulaire public est soumis (POST).
 *     2. Le serveur valide, insère, appelle cette fonction.
 *     3. L'URL retournée est transmise à la page de confirmation.
 *     4. JavaScript tente d'ouvrir automatiquement un nouvel onglet :
 *            window.open(lienWhatsapp, '_blank', 'noopener,noreferrer');
 *        IMPORTANT — piège identifié lors des réservations : window.open() après
 *        un rechargement de page consécutif à un submit n'est pas garanti
 *        (popup blockers, politiques de navigation mobile/desktop variables).
 *        Un lien cliquable de fallback DOIT être affiché en complément, toujours
 *        visible, même si la tentative automatique réussit.
 *
 *   Caractère non bloquant :
 *     Si le client ferme l'onglet WhatsApp sans envoyer, ou n'a pas WhatsApp,
 *     la commande reste valable — ce mécanisme est un bonus de notification.
 *
 * RÔLE DU MESSAGE :
 *   Rédigé à la première personne, comme si le client l'envoyait lui-même.
 *   Liste les articles, précise le mode, indique l'adresse si livraison.
 *   Le client lit le message pré-rempli dans WhatsApp et clique "Envoyer".
 *   Ce n'est PAS un envoi automatique silencieux.
 *
 * PARAMÈTRE $donneesCommande :
 *   Données validées issues du formulaire public, PAS une ligne relue depuis la base
 *   (la commande vient d'être insérée — on évite un SELECT inutile).
 *   Clés attendues : nom_client, telephone_client, mode, adresse_livraison, total.
 *
 * PARAMÈTRE $lignesCommande :
 *   Tableau des lignes de la commande.
 *   Clés attendues par ligne : plat_nom, quantite, sous_total.
 *
 * Normalisation du numéro et encodage de l'URL :
 *   waNormaliserNumero() (défini dans reservations_functions.php, inclus en tête de ce fichier)
 *   rawurlencode() sur le texte : encode accents, espaces (%20), retours à la ligne (%0A)
 *   pour que l'URL reste valide — distinct de htmlspecialchars() qui protège le HTML.
 *
 * @param array  $donneesCommande  Données validées du formulaire public
 * @param array  $lignesCommande   Lignes de la commande (plat_nom, quantite, sous_total)
 * @param string $whatsappNumero   Numéro WhatsApp du restaurant (peut être non normalisé)
 * @return string|null  URL wa.me prête à l'emploi, ou null si numéro non renseigné/invalide
 */
function buildWhatsappCommandeClientToRestaurantLink(
    array  $donneesCommande,
    array  $lignesCommande,
    string $whatsappNumero
): ?string {
    $numero = waNormaliserNumero($whatsappNumero);
    if ($numero === null) {
        return null;
    }

    $nom     = (string) ($donneesCommande['nom_client']        ?? '');
    $tel     = (string) ($donneesCommande['telephone_client']  ?? '');
    $mode    = (string) ($donneesCommande['mode']              ?? 'sur_place');
    $adresse = (string) ($donneesCommande['adresse_livraison'] ?? '');
    $total   = (float)  ($donneesCommande['total']             ?? 0);

    $modeLabel = $mode === 'livraison' ? 'en livraison' : 'sur place';

    $texte  = "Bonjour, je viens de passer une commande " . $modeLabel . ".\n\n";
    $texte .= "Articles commandés :\n";

    foreach ($lignesCommande as $ligne) {
        $platNom = (string) ($ligne['plat_nom']  ?? '');
        $qte     = (int)    ($ligne['quantite']  ?? 1);
        $st      = (float)  ($ligne['sous_total'] ?? 0);
        $texte  .= "- " . $platNom . " (×" . $qte . ") : "
                .  number_format($st, 2, ',', ' ') . "\n";
    }

    $texte .= "Total : " . number_format($total, 2, ',', ' ') . "\n\n";
    $texte .= "Mon nom : " . $nom . ".\n";
    $texte .= "Tél : " . $tel . ".";

    if ($mode === 'livraison' && $adresse !== '') {
        $texte .= "\nAdresse : " . $adresse . ".";
    }

    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte);
}

// ------------------------------------------------------------
// Flux 2 commandes — Restaurateur → Client, après changement de statut
// ------------------------------------------------------------

/**
 * Construit un lien wa.me pour que le RESTAURATEUR notifie le CLIENT
 * d'un changement de statut de sa commande (Flux 2 commandes).
 *
 * Raisonnement par statut :
 *   en_attente     → null  : état initial créé automatiquement à la commande. Si le
 *                            restaurateur revient manuellement à ce statut, c'est une
 *                            correction interne de workflow, pas un événement à
 *                            communiquer au client. La notification à la commande est
 *                            assurée par le Flux 1 (client → restaurant).
 *   confirmee      → oui   : le restaurateur a pris en charge la commande ; le client
 *                            est rassuré que sa demande est acceptée.
 *   en_preparation → oui   : la commande est active en cuisine ; évite les appels
 *                            "où en est ma commande ?".
 *   prete          → oui   : message bifurqué selon le mode.
 *                            Sur place : invitation à venir récupérer.
 *                            Livraison : "votre commande est en route".
 *   livree         → oui   : confirmation de fin de service + remerciement.
 *                            Utile comme preuve de livraison et outil de fidélisation.
 *   annulee        → oui   : information critique — le client doit savoir pour se
 *                            réorganiser (trouver une autre option alimentaire).
 *
 * @param array  $donneesCommande  Ligne commande avec au moins : nom_client, telephone_client, mode
 * @param string $nouveauStatut    Statut vers lequel la commande vient d'être changée
 * @param string $nomRestaurant    Nom du restaurant pour personnaliser les messages
 * @return string|null  URL wa.me prête à l'emploi, ou null si non applicable
 */
function buildWhatsappCommandeStatutClientLink(
    array  $donneesCommande,
    string $nouveauStatut,
    string $nomRestaurant = ''
): ?string {
    // en_attente : pas de message client (voir raisonnement dans le docblock)
    if (!in_array($nouveauStatut, ['confirmee', 'en_preparation', 'prete', 'livree', 'annulee'], true)) {
        return null;
    }

    $numero = waNormaliserNumero((string) ($donneesCommande['telephone_client'] ?? ''));
    if ($numero === null) {
        return null;
    }

    $nomClient = (string) ($donneesCommande['nom_client'] ?? '');
    $mode      = (string) ($donneesCommande['mode']       ?? 'sur_place');
    $chezResto = $nomRestaurant !== '' ? ' chez ' . $nomRestaurant : '';

    switch ($nouveauStatut) {

        case 'confirmee':
            $texte  = "Bonjour " . $nomClient . ",\n\n";
            $texte .= "Votre commande" . ($nomRestaurant !== '' ? ' passée' . $chezResto : '') . " a été confirmée.";
            $texte .= " Nous allons la traiter dès que possible. Merci !";
            break;

        case 'en_preparation':
            $texte  = "Bonjour " . $nomClient . ",\n\n";
            $texte .= "Bonne nouvelle : votre commande" . $chezResto . " est en cours de préparation !";
            break;

        case 'prete':
            $texte = "Bonjour " . $nomClient . ",\n\n";
            if ($mode === 'livraison') {
                $texte .= "Votre commande est prête et est en cours de livraison. Elle arrive bientôt !";
            } else {
                $texte .= "Votre commande est prête !";
                $texte .= $nomRestaurant !== ''
                    ? " Vous pouvez venir la récupérer" . $chezResto . "."
                    : " Vous pouvez venir la récupérer.";
            }
            break;

        case 'livree':
            $texte  = "Bonjour " . $nomClient . ",\n\n";
            $texte .= "Votre commande a bien été livrée. Merci pour votre confiance";
            $texte .= $nomRestaurant !== '' ? " et à bientôt" . $chezResto . " !" : " et à bientôt !";
            break;

        case 'annulee':
            $texte  = "Bonjour " . $nomClient . ",\n\n";
            $texte .= "Nous sommes navrés de vous informer que votre commande";
            $texte .= $chezResto . " n'a pas pu être honorée.\n\n";
            $texte .= "N'hésitez pas à nous contacter pour plus d'informations.";
            $texte .= $nomRestaurant !== '' ? "\n\nCordialement,\n" . $nomRestaurant : '';
            break;

        default:
            return null;
    }

    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte);
}
