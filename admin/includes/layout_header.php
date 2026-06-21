<?php
// Variables attendues de la page appelante :
//   $page_actuelle  (string) — slug de la page active, ex : 'categories', 'plats'...
//   $titre_page     (string, optionnel) — texte affiché dans <title>
//   $restaurant     (array|null, optionnel) — données restaurant déjà chargées par la page

if (!function_exists('h')) {
    function h(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    }
}

// Réutilise $restaurant si la page l'a déjà chargé, sinon le charge ici
if (!isset($restaurant)) {
    $restaurant = getRestaurantData();
}
$nomResto     = h((string) ($restaurant['nom'] ?? ''));
$page_actuelle = $page_actuelle ?? '';
$titre_page    = $titre_page    ?? 'Administration';

$navItems = [
    'dashboard'   => ['label' => 'Tableau de bord',  'url' => '/admin/dashboard.php'],
    'categories'  => ['label' => 'Catégories',        'url' => '/admin/categories.php'],
    'plats'       => ['label' => 'Plats',             'url' => '/admin/plats.php'],
    'reservations'=> ['label' => 'Réservations',      'url' => '/admin/reservations.php'],
    'commandes'   => ['label' => 'Commandes',         'url' => '/admin/commandes.php'],
    'restaurant'  => ['label' => 'Fiche restaurant',  'url' => '/admin/restaurant.php'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($titre_page) ?> — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="admin-layout">

    <!-- ============================================================
         Sidebar de navigation persistante
    ============================================================= -->
    <aside class="admin-sidebar">

        <div class="sidebar-brand">
            <?php if ($nomResto !== ''): ?>
                <span class="sidebar-brand-name"><?= $nomResto ?></span>
            <?php else: ?>
                <span class="sidebar-brand-name sidebar-brand-name--vide">Mon restaurant</span>
            <?php endif; ?>
        </div>

        <nav class="sidebar-nav" aria-label="Navigation principale">
            <ul>
                <?php foreach ($navItems as $slug => $item): ?>
                    <li>
                        <a href="<?= h($item['url']) ?>"
                           class="nav-link<?= $page_actuelle === $slug ? ' nav-link--active' : '' ?>"
                           <?= $page_actuelle === $slug ? 'aria-current="page"' : '' ?>>
                            <?= h($item['label']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>

        <div class="sidebar-footer">
            <a href="/admin/logout.php" class="nav-link nav-link--logout">
                Déconnexion
            </a>
        </div>

    </aside>

    <!-- ============================================================
         Zone de contenu principal — chaque page insère son contenu ici
    ============================================================= -->
    <main class="admin-main">
