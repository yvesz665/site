<?php
require_once __DIR__ . '/../config/database.php';

function getRestaurantData(): ?array
{
    try {
        $stmt = getDB()->query('SELECT * FROM restaurant LIMIT 1');
        $row  = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        error_log('[restaurant] getRestaurantData : ' . $e->getMessage());
        return null;
    }
}

/**
 * Un restaurant est "configuré" si ses trois champs obligatoires sont remplis.
 * Logo et horaires sont optionnels au départ.
 */
function isRestaurantConfigured(): bool
{
    $data = getRestaurantData();
    if ($data === null) {
        return false;
    }
    return trim((string) ($data['nom']       ?? '')) !== ''
        && trim((string) ($data['adresse']   ?? '')) !== ''
        && trim((string) ($data['telephone'] ?? '')) !== '';
}

/**
 * À appeler en haut de chaque page admin de gestion du menu.
 * Redirige vers la fiche restaurant si elle n'est pas encore configurée.
 */
function requireRestaurantConfigured(): void
{
    if (!isRestaurantConfigured()) {
        header('Location: /admin/restaurant.php');
        exit;
    }
}
