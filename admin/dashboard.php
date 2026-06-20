<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';

requireLogin();
requireRestaurantConfigured();

$restaurant = getRestaurantData();
$nomRestaurant = htmlspecialchars((string) ($restaurant['nom'] ?? ''), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de bord — <?= $nomRestaurant ?></title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card">

        <div class="panel-header">
            <div>
                <h1>Tableau de bord</h1>
                <p class="panel-subtitle">Bienvenue — <?= $nomRestaurant ?></p>
            </div>
            <a href="/admin/logout.php" class="btn-logout">Déconnexion</a>
        </div>

        <div class="dashboard-grid">

            <a href="/admin/categories.php" class="dashboard-card">
                <span class="card-icon">🗂️</span>
                <span class="card-title">Catégories</span>
                <span class="card-desc">Organiser les sections du menu</span>
            </a>

            <a href="/admin/plats.php" class="dashboard-card">
                <span class="card-icon">🍽️</span>
                <span class="card-title">Plats</span>
                <span class="card-desc">Ajouter, modifier, retirer des plats</span>
            </a>

            <a href="/admin/reservations.php" class="dashboard-card">
                <span class="card-icon">📅</span>
                <span class="card-title">Réservations</span>
                <span class="card-desc">Consulter et confirmer les réservations</span>
            </a>

            <a href="/admin/commandes.php" class="dashboard-card">
                <span class="card-icon">🛒</span>
                <span class="card-title">Commandes</span>
                <span class="card-desc">Suivre les commandes en cours</span>
            </a>

            <a href="/admin/restaurant.php" class="dashboard-card">
                <span class="card-icon">⚙️</span>
                <span class="card-title">Fiche restaurant</span>
                <span class="card-desc">Nom, adresse, horaires, logo</span>
            </a>

        </div>
    </div>
</div>
</body>
</html>
