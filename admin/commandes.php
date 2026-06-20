<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';
require_once __DIR__ . '/../includes/commandes_functions.php';

requireLogin();
requireRestaurantConfigured();

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

$restaurantId = (int) $_SESSION['restaurant_id'];
$erreurs      = [];

// -----------------------------------------------------------------------
// Libellés des statuts et modes (référence unique, réutilisée partout)
// -----------------------------------------------------------------------
$labelsStatut = [
    'en_attente'     => 'En attente',
    'confirmee'      => 'Confirmée',
    'en_preparation' => 'En préparation',
    'prete'          => 'Prête',
    'livree'         => 'Livrée',
    'annulee'        => 'Annulée',
];

$labelsMode = [
    'sur_place' => 'Sur place',
    'livraison' => 'Livraison',
];

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

$diffJours = (int) round((strtotime($dateSelectionnee) - strtotime($dateAujourdhui)) / 86400);

if ($diffJours === 0) {
    $labelPrincipal  = 'Aujourd\'hui';
    $labelEstAujourd = true;
} elseif ($diffJours === 1) {
    $labelPrincipal  = 'Demain';
    $labelEstAujourd = false;
} elseif ($diffJours === -1) {
    $labelPrincipal  = 'Hier';
    $labelEstAujourd = false;
} else {
    $labelPrincipal  = $jourNom;
    $labelEstAujourd = false;
}

$labelSecondaire = $jourNum . ' ' . $moisNom . ' ' . $anneeNum;

// -----------------------------------------------------------------------
// Message de succès (Pattern Post/Redirect/Get)
// -----------------------------------------------------------------------
$succes = (($_GET['ok'] ?? '') === '1') ? 'Statut de la commande mis à jour.' : '';

// -----------------------------------------------------------------------
// Traitement POST — changement de statut
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $commandeId    = (int) ($_POST['commande_id']    ?? 0);
        $nouveauStatut = trim($_POST['nouveau_statut'] ?? '');

        // Conservation de la date courante pour rediriger vers le même jour après l'action
        $dateCourante = trim($_POST['date_courante'] ?? $dateSelectionnee);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateCourante)
            || !checkdate(
                (int) substr($dateCourante, 5, 2),
                (int) substr($dateCourante, 8, 2),
                (int) substr($dateCourante, 0, 4)
            )
        ) {
            $dateCourante = $dateSelectionnee;
        }

        if ($commandeId > 0 && $nouveauStatut !== '') {
            $ok = updateCommandeStatut($commandeId, $restaurantId, $nouveauStatut);

            if ($ok) {
                header('Location: /admin/commandes.php?date=' . urlencode($dateCourante) . '&ok=1');
                exit;
            }
            $erreurs[] = 'Impossible de modifier cette commande. Elle n\'existe peut-être plus.';
        }
    }
}

// -----------------------------------------------------------------------
// Chargement des commandes pour la date sélectionnée
// -----------------------------------------------------------------------
$commandes   = getCommandesByDate($restaurantId, $dateSelectionnee);
$nbCommandes = count($commandes);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commandes — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card">

        <div class="panel-header">
            <h1>Commandes</h1>
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
            <a href="/admin/commandes.php?date=<?= h($datePrecedente) ?>"
               class="date-nav-btn" title="Jour précédent">← Précédent</a>

            <form method="get" action="/admin/commandes.php" class="date-nav-form">
                <input type="date" name="date"
                       value="<?= h($dateSelectionnee) ?>"
                       class="date-nav-input"
                       onchange="this.form.submit()"
                       title="Aller à une date précise">
                <noscript>
                    <button type="submit" class="btn-sm btn-secondary">Voir</button>
                </noscript>
            </form>

            <a href="/admin/commandes.php?date=<?= h($dateSuivante) ?>"
               class="date-nav-btn" title="Jour suivant">Suivant →</a>

            <?php if ($dateSelectionnee !== $dateAujourdhui): ?>
                <a href="/admin/commandes.php" class="date-nav-today">Aujourd'hui</a>
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
             Liste des commandes ou message vide
        ============================================================= -->
        <?php if ($nbCommandes === 0): ?>
            <p class="empty-state">
                Aucune commande pour ce jour.
                <?php if ($diffJours < 0): ?>
                    <br><span style="font-size:0.85rem;">Passez au
                        <a href="/admin/commandes.php">jour d'aujourd'hui</a>
                        ou utilisez le sélecteur ci-dessus.</span>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <p class="commandes-count">
                <?= $nbCommandes ?> commande<?= $nbCommandes > 1 ? 's' : '' ?>
            </p>
            <div class="commandes-list">
                <?php foreach ($commandes as $cmd):
                    $statut     = $cmd['statut'];
                    $heure      = substr((string) $cmd['created_at'], 11, 5);
                    $nbLignes   = count($cmd['lignes']);
                    $totalFormat = number_format((float) $cmd['total'], 2, ',', "\xc2\xa0");
                ?>
                <div class="commande-card commande-card--<?= h($statut) ?>">

                    <!-- En-tête : heure, badge statut, total, mode -->
                    <div class="cmd-header">
                        <span class="cmd-time"><?= h($heure) ?></span>
                        <span class="badge-statut badge-statut--<?= h($statut) ?>">
                            <?= h($labelsStatut[$statut] ?? $statut) ?>
                        </span>
                        <span class="cmd-mode-badge cmd-mode-badge--<?= h($cmd['mode']) ?>">
                            <?= h($labelsMode[$cmd['mode']] ?? $cmd['mode']) ?>
                        </span>
                        <span class="cmd-total"><?= h($totalFormat) ?></span>
                    </div>

                    <!-- Infos client -->
                    <div class="cmd-body">
                        <span class="cmd-client-name"><?= h($cmd['nom_client']) ?></span>
                        <span class="res-separator">·</span>
                        <span class="cmd-tel"><?= h($cmd['telephone_client']) ?></span>
                    </div>

                    <!-- Adresse de livraison si applicable -->
                    <?php if ($cmd['mode'] === 'livraison' && !empty($cmd['adresse_livraison'])): ?>
                        <div class="cmd-adresse">
                            Livraison : <?= h($cmd['adresse_livraison']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- ================================================
                         Détail des lignes via <details>/<summary> HTML5
                         Zéro JS, zéro rechargement : le navigateur gère
                         nativement le toggle ouvert/fermé.
                    ================================================== -->
                    <?php if ($nbLignes > 0): ?>
                        <details class="cmd-detail">
                            <summary class="cmd-detail-summary">
                                <?= $nbLignes ?> article<?= $nbLignes > 1 ? 's' : '' ?>
                                — total <?= h($totalFormat) ?>
                            </summary>
                            <table class="cmd-lignes-table">
                                <thead>
                                    <tr>
                                        <th>Plat</th>
                                        <th class="col-num">Qté</th>
                                        <th class="col-num">Prix unit.</th>
                                        <th class="col-num">Sous-total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cmd['lignes'] as $ligne): ?>
                                        <tr>
                                            <td><?= h($ligne['plat_nom']) ?></td>
                                            <td class="col-num"><?= (int) $ligne['quantite'] ?></td>
                                            <td class="col-num">
                                                <?= h(number_format((float) $ligne['prix_unitaire'], 2, ',', "\xc2\xa0")) ?>
                                            </td>
                                            <td class="col-num">
                                                <?= h(number_format((float) $ligne['sous_total'], 2, ',', "\xc2\xa0")) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="cmd-lignes-total">
                                        <td colspan="3">Total</td>
                                        <td class="col-num"><?= h($totalFormat) ?></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </details>
                    <?php endif; ?>

                    <!-- Formulaire de changement de statut (sélecteur libre) -->
                    <form method="post" action="/admin/commandes.php" class="cmd-statut-form">
                        <input type="hidden" name="csrf_token"    value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="commande_id"   value="<?= (int) $cmd['id'] ?>">
                        <input type="hidden" name="date_courante" value="<?= h($dateSelectionnee) ?>">
                        <select name="nouveau_statut" class="select-statut">
                            <?php foreach ($labelsStatut as $val => $lbl): ?>
                                <option value="<?= h($val) ?>"<?= $statut === $val ? ' selected' : '' ?>>
                                    <?= h($lbl) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-sm btn-primary">Enregistrer</button>
                    </form>

                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>
</body>
</html>
