<?php
$configLocal = __DIR__ . '/database.local.php';
if (!file_exists($configLocal)) {
    throw new RuntimeException(
        'Fichier de configuration manquant : config/database.local.php. ' .
        'Copiez config/database.example.php vers config/database.local.php et renseignez vos identifiants.'
    );
}
require_once $configLocal;

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
