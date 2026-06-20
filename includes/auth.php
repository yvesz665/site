<?php
require_once __DIR__ . '/../config/database.php';

// Démarre la session avec des paramètres de cookie sécurisés.
// Le flag 'secure' est conditionnel : true uniquement si la requête arrive via HTTPS.
// En développement local (XAMPP sans TLS), HTTPS n'est pas actif donc secure=false,
// ce qui permet de tester sans erreur. En production sur Hostinger (HTTPS actif),
// secure=true garantit que le cookie de session n'est jamais transmis en clair sur HTTP.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

// -----------------------------------------------------------------------
// Restaurant (installation mono-restaurant : une seule ligne en base)
// -----------------------------------------------------------------------

function getRestaurantId(): ?int
{
    try {
        $stmt = getDB()->query('SELECT id FROM restaurant LIMIT 1');
        $row  = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    } catch (Exception $e) {
        error_log('[auth] getRestaurantId : ' . $e->getMessage());
        return null;
    }
}

// -----------------------------------------------------------------------
// Vérification et protection de session
// -----------------------------------------------------------------------

function isLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']) && !empty($_SESSION['restaurant_id']);
}

/**
 * À appeler en haut de chaque page admin protégée.
 * Redirige vers la page de connexion si la session est absente ou invalide.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /admin/login.php');
        exit;
    }
}

// -----------------------------------------------------------------------
// Connexion / déconnexion
// -----------------------------------------------------------------------

function loginUser(string $email, string $password): bool
{
    $restaurantId = getRestaurantId();
    if ($restaurantId === null) {
        return false;
    }

    try {
        $stmt = getDB()->prepare(
            'SELECT id, mot_de_passe_hash
             FROM admin_users
             WHERE email = ? AND restaurant_id = ?
             LIMIT 1'
        );
        $stmt->execute([$email, $restaurantId]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['mot_de_passe_hash'])) {
            return false;
        }

        // Régénère l'ID de session pour prévenir la fixation de session :
        // si un attaquant avait forcé un ID de session connu avant la connexion,
        // il se retrouve avec un ID devenu invalide après le login.
        session_regenerate_id(true);

        $_SESSION['admin_id']      = (int) $admin['id'];
        $_SESSION['admin_email']   = $email;
        $_SESSION['restaurant_id'] = $restaurantId;

        $upd = getDB()->prepare(
            'UPDATE admin_users SET derniere_connexion = NOW() WHERE id = ?'
        );
        $upd->execute([$admin['id']]);

        return true;
    } catch (Exception $e) {
        error_log('[auth] loginUser : ' . $e->getMessage());
        return false;
    }
}

function logoutUser(): void
{
    // 1. Vide toutes les variables de session en mémoire
    session_unset();

    // 2. Supprime le cookie de session côté client en le faisant expirer dans le passé
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // 3. Détruit les données de session côté serveur
    session_destroy();
}

// -----------------------------------------------------------------------
// Protection CSRF
// -----------------------------------------------------------------------
// Mécanisme en trois étapes :
//
// 1. GÉNÉRATION : generateCsrfToken() crée un token aléatoire de 64 caractères
//    hexadécimaux (via random_bytes, cryptographiquement sûr) et le stocke en session.
//    Il est inséré comme champ caché <input type="hidden"> dans chaque formulaire.
//
// 2. VÉRIFICATION : à la soumission (POST), verifyCsrfToken() compare le token reçu
//    avec celui en session via hash_equals() — comparaison en temps constant qui évite
//    les timing attacks (contrairement à l'opérateur ===).
//
// 3. PROTECTION : un site tiers malveillant ne peut pas lire la session de la victime
//    (politique same-origin du navigateur), donc il ne peut pas reproduire le token
//    dans un formulaire forgé → l'attaque CSRF est neutralisée.
// -----------------------------------------------------------------------

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $tokenSoumis): bool
{
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $tokenSoumis);
}
