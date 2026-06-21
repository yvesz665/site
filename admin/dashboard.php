<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';

requireLogin();
requireRestaurantConfigured();

// $restaurant est chargé ici — layout_header le réutilisera sans second appel
$restaurant    = getRestaurantData();
$nomRestaurant = htmlspecialchars((string) ($restaurant['nom'] ?? ''), ENT_QUOTES, 'UTF-8');

// -------------------------------------------------------------------
// Aucun traitement POST sur cette page — aucune redirection possible.
// L'include du layout peut donc être appelé immédiatement.
// -------------------------------------------------------------------

$page_actuelle = 'dashboard';
$titre_page    = 'Tableau de bord';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="panel-card">
    <div class="panel-header">
        <div>
            <h1>Tableau de bord</h1>
            <p class="panel-subtitle">Bienvenue — <?= $nomRestaurant ?></p>
        </div>
    </div>

    <p class="dashboard-welcome">
        Utilisez la navigation à gauche pour gérer votre restaurant.
    </p>
</div>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
