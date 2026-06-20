<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';
require_once __DIR__ . '/../includes/reservations_functions.php';

requireLogin();
requireRestaurantConfigured();

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

$restaurantId  = (int) $_SESSION['restaurant_id'];
$erreurs       = [];
$restaurant    = getRestaurantData();
$nomRestaurant = (string) ($restaurant['nom'] ?? '');

// -----------------------------------------------------------------------
// Sélection et validation de la date
// -----------------------------------------------------------------------
$dateParam = trim($_GET['date'] ?? '');

$dateSelectionnee = date('Y-m-d'); // valeur par défaut : aujourd'hui

if ($dateParam !== '') {
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateParam, $m)
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
    ) {
        $dateSelectionnee = $dateParam;
    }
    // Si le format est invalide, on reste sur aujourd'hui sans message d'erreur —
    // l'utilisateur arrive sur la page avec la date courante, ce qui est cohérent.
}

// Navigation jour précédent / suivant
$dtSel          = new DateTime($dateSelectionnee);
$datePrecedente = (clone $dtSel)->modify('-1 day')->format('Y-m-d');
$dateSuivante   = (clone $dtSel)->modify('+1 day')->format('Y-m-d');
$dateAujourdhui = date('Y-m-d');

// -----------------------------------------------------------------------
// Mise en forme de la date en français
// -----------------------------------------------------------------------
$joursNoms = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$moisNoms  = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
              'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

$tsDate   = strtotime($dateSelectionnee);
$jourNom  = $joursNoms[(int) date('w', $tsDate)];
$jourNum  = (int) date('j', $tsDate);
$moisNom  = $moisNoms[(int) date('n', $tsDate)];
$anneeNum = (int) date('Y', $tsDate);

// Libellé principal selon la proximité de la date sélectionnée
$diffJours = (int) round((strtotime($dateSelectionnee) - strtotime($dateAujourdhui)) / 86400);

if ($diffJours === 0) {
    $labelPrincipal = 'Aujourd\'hui';
    $labelEstAujourd = true;
} elseif ($diffJours === 1) {
    $labelPrincipal = 'Demain';
    $labelEstAujourd = false;
} elseif ($diffJours === -1) {
    $labelPrincipal = 'Hier';
    $labelEstAujourd = false;
} else {
    $labelPrincipal = $jourNom;
    $labelEstAujourd = false;
}

$labelSecondaire = $jourNum . ' ' . $moisNom . ' ' . $anneeNum;
// Ex : "Dimanche 25 juin 2026"

// -----------------------------------------------------------------------
// Messages de succès (Pattern Post/Redirect/Get)
// -----------------------------------------------------------------------
$messagesOk = [
    'confirme' => 'Réservation confirmée.',
    'annule'   => 'Réservation annulée.',
    'attente'  => 'Réservation remise en attente.',
];
$succes = $messagesOk[$_GET['ok'] ?? ''] ?? '';

// -----------------------------------------------------------------------
// Actions disponibles par statut actuel
// -----------------------------------------------------------------------
$actionsParStatut = [
    'en_attente' => [
        ['action' => 'confirmer',        'label' => 'Confirmer',           'class' => 'btn-success'],
        ['action' => 'annuler',          'label' => 'Annuler',             'class' => 'btn-danger'],
    ],
    'confirmee' => [
        ['action' => 'annuler',          'label' => 'Annuler',             'class' => 'btn-danger'],
        ['action' => 'remettre_attente', 'label' => 'Remettre en attente', 'class' => 'btn-secondary'],
    ],
    'annulee' => [
        ['action' => 'remettre_attente', 'label' => 'Remettre en attente', 'class' => 'btn-secondary'],
    ],
];

// Correspondance action POST → nouveau statut BD
$actionVersStatut = [
    'confirmer'        => 'confirmee',
    'annuler'          => 'annulee',
    'remettre_attente' => 'en_attente',
];

// Correspondance nouveau statut → clé du message de succès PRG
$statutVersOkKey = [
    'confirmee'  => 'confirme',
    'annulee'    => 'annule',
    'en_attente' => 'attente',
];

// -----------------------------------------------------------------------
// Traitement POST — changement de statut
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action        = $_POST['action']         ?? '';
        $reservationId = (int) ($_POST['reservation_id'] ?? 0);

        // Récupère la date courante transmise par le formulaire pour rediriger
        // vers la même date après l'action (conservation du contexte de navigation)
        $dateCourante  = trim($_POST['date_courante'] ?? $dateSelectionnee);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateCourante)
            || !checkdate(
                (int) substr($dateCourante, 5, 2),
                (int) substr($dateCourante, 8, 2),
                (int) substr($dateCourante, 0, 4)
            )
        ) {
            $dateCourante = $dateSelectionnee;
        }

        if (isset($actionVersStatut[$action]) && $reservationId > 0) {
            $newStatut = $actionVersStatut[$action];
            $ok = updateReservationStatus($reservationId, $restaurantId, $newStatut);

            if ($ok) {
                $okKey = $statutVersOkKey[$newStatut] ?? '';
                header('Location: /admin/reservations.php?date=' . urlencode($dateCourante)
                    . ($okKey !== '' ? '&ok=' . $okKey : ''));
                exit;
            }
            $erreurs[] = 'Impossible de modifier cette réservation. Elle n\'existe peut-être plus.';
        }
    }
}

// -----------------------------------------------------------------------
// Chargement des réservations pour la date sélectionnée
// -----------------------------------------------------------------------
$reservations = getReservationsByDate($restaurantId, $dateSelectionnee);
$nbReservations = count($reservations);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Réservations — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card">

        <div class="panel-header">
            <h1>Réservations</h1>
            <a href="/admin/dashboard.php" class="lien-retour">← Tableau de bord</a>
        </div>

        <?php if ($succes !== ''): ?>
            <div class="alert alert-success"><?= h($succes) ?></div>
        <?php endif; ?>

        <?php if (!empty($erreurs)): ?>
            <div class="alert alert-error">
                <ul>
                    <?php foreach ($erreurs as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- ============================================================
             Navigation par date
        ============================================================= -->
        <div class="date-nav">
            <a href="/admin/reservations.php?date=<?= h($datePrecedente) ?>"
               class="date-nav-btn" title="Jour précédent">← Précédent</a>

            <form method="get" action="/admin/reservations.php" class="date-nav-form">
                <input type="date" name="date"
                       value="<?= h($dateSelectionnee) ?>"
                       class="date-nav-input"
                       onchange="this.form.submit()"
                       title="Aller à une date précise">
                <noscript>
                    <button type="submit" class="btn-sm btn-secondary">Voir</button>
                </noscript>
            </form>

            <a href="/admin/reservations.php?date=<?= h($dateSuivante) ?>"
               class="date-nav-btn" title="Jour suivant">Suivant →</a>

            <?php if ($dateSelectionnee !== $dateAujourdhui): ?>
                <a href="/admin/reservations.php" class="date-nav-today">Aujourd'hui</a>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             En-tête de date
        ============================================================= -->
        <div class="date-group-header">
            <span class="date-label-principal <?= $labelEstAujourd ? 'date-label--today' : '' ?>">
                <?= h($labelPrincipal) ?>
            </span>
            <span class="date-label-secondaire">
                <?= $labelPrincipal !== $jourNom
                    ? h($jourNom . ' ' . $labelSecondaire)
                    : h($labelSecondaire) ?>
            </span>
        </div>

        <!-- ============================================================
             Liste des réservations ou message vide
        ============================================================= -->
        <?php if ($nbReservations === 0): ?>
            <p class="empty-state">
                Aucune réservation pour ce jour.
                <?php if ($diffJours < 0): ?>
                    <br><span style="font-size:0.85rem;">Passez au
                        <a href="/admin/reservations.php">jour d'aujourd'hui</a>
                        ou utilisez le sélecteur ci-dessus.</span>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="reservations-count">
                <?= $nbReservations ?> réservation<?= $nbReservations > 1 ? 's' : '' ?>
            </p>
            <div class="reservations-list">
                <?php foreach ($reservations as $res):
                    $statut        = $res['statut'];
                    $heure         = substr((string) $res['heure_reservation'], 0, 5);
                    $actionsDispos = $actionsParStatut[$statut] ?? [];

                    // Flux B : lien vers le téléphone du client — uniquement si statut final
                    $lienWaClient = null;
                    if (in_array($statut, ['confirmee', 'annulee'], true)) {
                        $lienWaClient = buildWhatsappClientNotifLink($res, $statut, $nomRestaurant);
                    }
                ?>
                <div class="reservation-card reservation-card--<?= h($statut) ?>">

                    <div class="res-header">
                        <span class="res-time"><?= h($heure) ?></span>
                        <span class="badge-statut badge-statut--<?= h($statut) ?>">
                            <?php
                            $labelsStatut = [
                                'en_attente' => 'En attente',
                                'confirmee'  => 'Confirmée',
                                'annulee'    => 'Annulée',
                            ];
                            echo h($labelsStatut[$statut] ?? $statut);
                            ?>
                        </span>
                    </div>

                    <div class="res-body">
                        <span class="res-client-name"><?= h($res['nom_client']) ?></span>
                        <span class="res-separator">·</span>
                        <span><?= (int) $res['nombre_personnes'] ?> personne<?= $res['nombre_personnes'] > 1 ? 's' : '' ?></span>
                        <span class="res-separator">·</span>
                        <span class="res-tel"><?= h($res['telephone_client']) ?></span>
                    </div>

                    <?php if (!empty($res['notes'])): ?>
                        <div class="res-notes"><?= h($res['notes']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($actionsDispos)): ?>
                        <div class="res-actions">
                            <?php foreach ($actionsDispos as $btn): ?>
                                <form method="post" action="/admin/reservations.php" class="form-btn-inline">
                                    <input type="hidden" name="csrf_token"     value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"         value="<?= h($btn['action']) ?>">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $res['id'] ?>">
                                    <input type="hidden" name="date_courante"  value="<?= h($dateSelectionnee) ?>">
                                    <button type="submit" class="btn-sm <?= h($btn['class']) ?>">
                                        <?= h($btn['label']) ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Flux B : notifier le client de la confirmation / annulation -->
                    <?php if ($lienWaClient !== null): ?>
                        <div class="res-whatsapp">
                            <a href="<?= h($lienWaClient) ?>"
                               class="btn-sm btn-wa-client"
                               target="_blank"
                               rel="noopener noreferrer"
                               title="Ouvre WhatsApp avec un message destiné au client de cette réservation">
                                Notifier le client sur WhatsApp
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
