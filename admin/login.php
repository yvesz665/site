<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$restaurantId = getRestaurantId();
$adminExiste  = false;
$erreur       = '';

if ($restaurantId !== null) {
    try {
        $stmt = getDB()->prepare(
            'SELECT COUNT(*) FROM admin_users WHERE restaurant_id = ?'
        );
        $stmt->execute([$restaurantId]);
        $adminExiste = (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        error_log('[login] vérification admin existant : ' . $e->getMessage());
    }
}

if ($adminExiste && $_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreur = 'Jeton de sécurité invalide. Veuillez recharger la page et réessayer.';
    } else {
        $email      = trim($_POST['email'] ?? '');
        $motDePasse = $_POST['mot_de_passe'] ?? '';

        if (!loginUser($email, $motDePasse)) {
            // Message volontairement générique : ne précise pas si c'est l'email
            // ou le mot de passe qui est incorrect, pour ne pas permettre
            // l'énumération de comptes existants.
            $erreur = 'Email ou mot de passe incorrect.';
        } else {
            header('Location: /admin/dashboard.php');
            exit;
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-logo">🍽️</div>
        <h1>Administration</h1>

        <?php if (!$adminExiste): ?>
            <div class="alert alert-info">
                Aucun compte administrateur n'existe encore pour ce restaurant.<br>
                <a href="/admin/inscription.php">Créer le compte administrateur</a>
            </div>

        <?php else: ?>

            <?php if ($erreur !== ''): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($erreur, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/login.php" novalidate>
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-group">
                    <label for="email">Adresse email</label>
                    <input type="email" id="email" name="email"
                           autocomplete="email" required autofocus>
                </div>

                <div class="form-group">
                    <label for="mot_de_passe">Mot de passe</label>
                    <input type="password" id="mot_de_passe" name="mot_de_passe"
                           autocomplete="current-password" required>
                </div>

                <button type="submit" class="btn-primary">Se connecter</button>
            </form>

        <?php endif; ?>
    </div>
</div>
</body>
</html>
