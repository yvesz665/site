<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$restaurantId = getRestaurantId();
$bloque       = false;
$erreurs      = [];

// Vérifie si un compte admin existe déjà pour ce restaurant
if ($restaurantId === null) {
    $erreurs[] = 'Aucun restaurant trouvé en base de données. '
               . 'Veuillez d\'abord initialiser la table restaurant.';
} else {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM admin_users WHERE restaurant_id = ?'
        );
        $stmt->execute([$restaurantId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $bloque = true;
        }
    } catch (Exception $e) {
        error_log('[inscription] vérification admin existant : ' . $e->getMessage());
        $erreurs[] = 'Une erreur technique est survenue. Veuillez réessayer.';
    }
}

// Traitement POST uniquement si l'inscription est possible
if (!$bloque && empty($erreurs) && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page et réessayer.';
    } else {
        $email        = trim($_POST['email'] ?? '');
        $motDePasse   = $_POST['mot_de_passe'] ?? '';
        $confirmation = $_POST['confirmation'] ?? '';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse email n\'est pas valide.';
        }
        if (strlen($motDePasse) < 8) {
            $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
        }
        if ($motDePasse !== $confirmation) {
            $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
        }

        if (empty($erreurs)) {
            try {
                $hash = password_hash($motDePasse, PASSWORD_DEFAULT);

                $stmt = getDB()->prepare(
                    'INSERT INTO admin_users (restaurant_id, email, mot_de_passe_hash)
                     VALUES (?, ?, ?)'
                );
                $stmt->execute([$restaurantId, $email, $hash]);
                $nouvelAdminId = (int) getDB()->lastInsertId();

                // Connexion automatique après inscription réussie
                session_regenerate_id(true);
                $_SESSION['admin_id']      = $nouvelAdminId;
                $_SESSION['admin_email']   = $email;
                $_SESSION['restaurant_id'] = $restaurantId;

                header('Location: /admin/restaurant.php');
                exit;
            } catch (Exception $e) {
                error_log('[inscription] INSERT admin_users : ' . $e->getMessage());
                $erreurs[] = 'Une erreur technique est survenue lors de la création du compte.';
            }
        }
    }
}

$csrfToken  = generateCsrfToken();
$emailSaisi = htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Créer le compte administrateur</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo">🍽️</div>
        <h1>Créer le compte administrateur</h1>

        <?php if ($bloque): ?>
            <div class="alert alert-info">
                Un compte administrateur existe déjà pour ce restaurant.<br>
                Contactez votre prestataire si vous avez perdu vos accès.
            </div>

        <?php else: ?>

            <?php if (!empty($erreurs)): ?>
                <div class="alert alert-error">
                    <ul>
                        <?php foreach ($erreurs as $err): ?>
                            <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/inscription.php" novalidate>
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="email">Adresse email</label>
                    <input type="email" id="email" name="email"
                           value="<?= $emailSaisi ?>"
                           autocomplete="email" required autofocus>
                </div>

                <div class="form-group">
                    <label for="mot_de_passe">
                        Mot de passe
                        <span class="hint">8 caractères minimum</span>
                    </label>
                    <input type="password" id="mot_de_passe" name="mot_de_passe"
                           autocomplete="new-password" required>
                </div>

                <div class="form-group">
                    <label for="confirmation">Confirmer le mot de passe</label>
                    <input type="password" id="confirmation" name="confirmation"
                           autocomplete="new-password" required>
                </div>

                <button type="submit" class="btn-primary">Créer le compte</button>
            </form>

            <p class="auth-link"><a href="/admin/login.php">Déjà un compte ? Se connecter</a></p>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
