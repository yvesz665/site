<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Retourne toutes les réservations d'un restaurant pour une date donnée,
 * triées par heure de réservation croissante.
 *
 * @param int    $restaurantId
 * @param string $date  Format YYYY-MM-DD (validé par l'appelant)
 * @return array  Tableau de lignes PDO, vide en cas d'erreur ou d'absence
 */
function getReservationsByDate(int $restaurantId, string $date): array
{
    // Défense en profondeur : valide le format même si l'appelant a déjà validé
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return [];
    }

    try {
        $stmt = getDB()->prepare(
            'SELECT id, nom_client, telephone_client, date_reservation,
                    heure_reservation, nombre_personnes, statut, notes
             FROM reservations
             WHERE restaurant_id = ? AND date_reservation = ?
             ORDER BY heure_reservation ASC'
        );
        $stmt->execute([$restaurantId, $date]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('[reservations] getReservationsByDate : ' . $e->getMessage());
        return [];
    }
}

/**
 * Met à jour le statut d'une réservation en vérifiant l'appartenance au restaurant.
 *
 * La clause WHERE id = ? AND restaurant_id = ? garantit qu'un admin ne peut pas
 * modifier la réservation d'un autre restaurant, même en devinant l'id.
 *
 * @param int    $reservationId
 * @param int    $restaurantId   Utilisé comme garde d'appartenance, pas de confiance aveugle
 * @param string $newStatut      Doit être l'une des trois valeurs de l'enum
 * @return bool  true si une ligne a été modifiée, false sinon (introuvable ou statut invalide)
 */
function updateReservationStatus(int $reservationId, int $restaurantId, string $newStatut): bool
{
    // Défense en profondeur : valide le statut avant d'interroger la base,
    // même si la contrainte ENUM en rejetterait une valeur invalide de toute façon.
    $statutsValides = ['en_attente', 'confirmee', 'annulee'];
    if (!in_array($newStatut, $statutsValides, true)) {
        return false;
    }

    try {
        $stmt = getDB()->prepare(
            'UPDATE reservations
             SET statut = ?
             WHERE id = ? AND restaurant_id = ?'
        );
        $stmt->execute([$newStatut, $reservationId, $restaurantId]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        error_log('[reservations] updateReservationStatus : ' . $e->getMessage());
        return false;
    }
}

// ------------------------------------------------------------
// Fonctions utilitaires internes (privées par convention)
// ------------------------------------------------------------

/**
 * Formate une date YYYY-MM-DD en français lisible pour les messages WhatsApp.
 * Ex : "2026-06-20" → "samedi 20 juin 2026"
 * Utilise des tableaux statiques au lieu de IntlDateFormatter pour éviter
 * la dépendance à l'extension intl, absente sur certains hébergements mutualisés.
 */
function waFormatDateFr(string $dateYmd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        return $dateYmd;
    }
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
              'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    $ts = strtotime($dateYmd);
    return $jours[(int) date('w', $ts)]
        . ' ' . (int) date('j', $ts)
        . ' ' . $mois[(int) date('n', $ts)]
        . ' ' . (int) date('Y', $ts);
}

/**
 * Normalise un numéro de téléphone pour l'utilisation dans une URL wa.me.
 *
 * wa.me attend des chiffres uniquement, sans +, sans espaces ni tirets.
 * Étapes :
 *   1. Supprimer tous les non-chiffres (espaces, tirets, parenthèses, +).
 *   2. Supprimer le préfixe "00" (notation internationale alternative : "0033…" → "33…").
 *   3. Rejeter si hors de la plage E.164 (7 à 15 chiffres).
 *
 * Limite connue : un numéro local sans indicatif pays (ex : "0612345678" en France)
 * passe la validation mais ne sera pas résolu correctement par WhatsApp. Le restaurateur
 * doit saisir les numéros en format international (ex : "33612345678" pour la France,
 * "22670000000" pour le Burkina Faso).
 *
 * @return string|null  Chiffres prêts pour wa.me, ou null si non utilisable
 */
function waNormaliserNumero(string $telephone): ?string
{
    $chiffres = preg_replace('/\D/', '', $telephone);

    // "00" en tête = préfixe d'appel international, équivalent au "+" : on le retire
    if (substr($chiffres, 0, 2) === '00') {
        $chiffres = substr($chiffres, 2);
    }

    $len = strlen($chiffres);
    if ($len < 7 || $len > 15) {
        return null;
    }

    return $chiffres;
}

// ------------------------------------------------------------
// Flux A — Notification interne (restaurateur → son propre numéro)
// ------------------------------------------------------------

/**
 * Construit un lien wa.me pour que le restaurateur se notifie lui-même
 * d'une nouvelle réservation (Flux A).
 *
 * Le lien ouvre WhatsApp avec un message récapitulatif pré-rempli, destiné
 * au numéro WhatsApp du restaurant. Le restaurateur doit cliquer "Envoyer"
 * lui-même — ce n'est PAS un envoi automatique silencieux.
 *
 * Pourquoi rawurlencode() sur le texte du message :
 *   Le paramètre ?text= fait partie d'une URL. Tous les caractères non-ASCII
 *   (accents : é, à, ê…), les espaces et les retours à la ligne (\n → %0A)
 *   doivent être convertis en séquences %XX pour que l'URL soit valide et que
 *   WhatsApp recompose correctement le texte. rawurlencode() encode les espaces
 *   en %20 (RFC 3986), plus correct qu'urlencode() qui produit + à la place.
 *
 *   Distinction des deux échappements :
 *   - rawurlencode() → chaîne valide dans une URL (paramètre HTTP).
 *   - htmlspecialchars() → chaîne sûre dans du HTML (valeur d'attribut href).
 *   Ces deux fonctions sont complémentaires : l'URL retournée ici doit ensuite
 *   passer dans htmlspecialchars() avant d'être insérée dans un href="".
 *
 * @param array  $reservation    Ligne complète de la table reservations
 * @param string $whatsappNumero Numéro du restaurant (peut être non normalisé)
 * @return string|null  URL wa.me prête à l'emploi, ou null si numéro inutilisable
 */
function buildWhatsappRestaurantNotifLink(array $reservation, string $whatsappNumero): ?string
{
    $numero = waNormaliserNumero($whatsappNumero);
    if ($numero === null) {
        return null;
    }

    $date  = waFormatDateFr((string) ($reservation['date_reservation']  ?? ''));
    $heure = substr((string) ($reservation['heure_reservation'] ?? ''), 0, 5);
    $nb    = (int) ($reservation['nombre_personnes'] ?? 0);

    $texte  = "Nouvelle réservation :\n";
    $texte .= "Nom : "       . ($reservation['nom_client']       ?? '') . "\n";
    $texte .= "Tél : "       . ($reservation['telephone_client'] ?? '') . "\n";
    $texte .= "Date : "      . $date                                    . "\n";
    $texte .= "Heure : "     . $heure                                   . "\n";
    $texte .= "Personnes : " . $nb;
    if (!empty($reservation['notes'])) {
        $texte .= "\nNotes : " . $reservation['notes'];
    }

    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte);
}

// ------------------------------------------------------------
// Flux B — Notification client (restaurateur → téléphone du client)
// ------------------------------------------------------------

/**
 * Construit un lien wa.me pour que le restaurateur notifie le client
 * du changement de statut de sa réservation (Flux B).
 *
 * Uniquement disponible pour les statuts 'confirmee' et 'annulee',
 * car une remise en attente n'est pas un événement communiqué au client.
 *
 * Le numéro cible est telephone_client de la réservation. S'il est vide
 * ou non utilisable (trop court après normalisation), la fonction retourne null.
 *
 * Limite connue sur la normalisation du numéro client : identique à
 * waNormaliserNumero() — les numéros locaux sans indicatif pays ne seront
 * pas résolus correctement par WhatsApp (voir docblock de waNormaliserNumero).
 *
 * @param array  $reservation    Ligne complète de la table reservations
 * @param string $nouveauStatut  'confirmee' ou 'annulee' (autres valeurs → null)
 * @param string $nomRestaurant  Nom du restaurant, inclus dans le message de confirmation
 * @return string|null  URL wa.me prête à l'emploi, ou null si non applicable
 */
function buildWhatsappClientNotifLink(
    array  $reservation,
    string $nouveauStatut,
    string $nomRestaurant = ''
): ?string {
    if (!in_array($nouveauStatut, ['confirmee', 'annulee'], true)) {
        return null;
    }

    $numero = waNormaliserNumero((string) ($reservation['telephone_client'] ?? ''));
    if ($numero === null) {
        return null;
    }

    $nomClient = (string) ($reservation['nom_client'] ?? '');
    $date      = waFormatDateFr((string) ($reservation['date_reservation'] ?? ''));
    $heure     = substr((string) ($reservation['heure_reservation'] ?? ''), 0, 5);
    $nb        = (int) ($reservation['nombre_personnes'] ?? 0);
    $restoPart = $nomRestaurant !== '' ? ' au ' . $nomRestaurant : '';

    if ($nouveauStatut === 'confirmee') {
        $texte  = "Bonjour " . $nomClient . ",\n\n";
        $texte .= "Votre réservation" . $restoPart . " est confirmée :\n";
        $texte .= "- Date : "      . $date  . "\n";
        $texte .= "- Heure : "     . $heure . "\n";
        $texte .= "- Personnes : " . $nb    . "\n\n";
        $texte .= "À bientôt !";
    } else {
        // annulee
        $texte  = "Bonjour " . $nomClient . ",\n\n";
        $texte .= "Nous sommes désolés de vous informer que votre réservation";
        $texte .= " prévue le " . $date . " à " . $heure . " n'a pas pu être maintenue.\n\n";
        $texte .= "N'hésitez pas à nous contacter pour prévoir une nouvelle date.\n\n";
        $texte .= "Cordialement" . ($nomRestaurant !== '' ? ",\n" . $nomRestaurant : '.');
    }

    return 'https://wa.me/' . $numero . '?text=' . rawurlencode($texte);
}
