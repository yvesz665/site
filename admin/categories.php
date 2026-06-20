<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';

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

// Messages de succès transmis par le pattern Post/Redirect/Get
$messagesOk = [
    'ajouter'   => 'Catégorie ajoutée avec succès.',
    'modifier'  => 'Catégorie modifiée avec succès.',
    'supprimer' => 'Catégorie supprimée.',
];
$succes = $messagesOk[$_GET['ok'] ?? ''] ?? '';

// -----------------------------------------------------------------------
// Traitement POST (toutes les actions passent par ici)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action      = $_POST['action']       ?? '';
        $categorieId = (int) ($_POST['categorie_id'] ?? 0);

        switch ($action) {

            // -----------------------------------------------------------
            case 'ajouter':
                $nom = trim($_POST['nom'] ?? '');
                if ($nom === '') {
                    $erreurs[] = 'Le nom de la catégorie est obligatoire.';
                } elseif (mb_strlen($nom) > 255) {
                    $erreurs[] = 'Le nom ne doit pas dépasser 255 caractères.';
                } else {
                    try {
                        // Prochain ordre = max existant + 1, ou 1 si aucune catégorie
                        $stmtOrdre = getDB()->prepare(
                            'SELECT COALESCE(MAX(ordre_affichage), 0) + 1
                             FROM categories WHERE restaurant_id = ?'
                        );
                        $stmtOrdre->execute([$restaurantId]);
                        $prochainOrdre = (int) $stmtOrdre->fetchColumn();

                        $stmt = getDB()->prepare(
                            'INSERT INTO categories (restaurant_id, nom, ordre_affichage)
                             VALUES (?, ?, ?)'
                        );
                        $stmt->execute([$restaurantId, $nom, $prochainOrdre]);

                        header('Location: /admin/categories.php?ok=ajouter');
                        exit;
                    } catch (Exception $e) {
                        error_log('[categories] INSERT : ' . $e->getMessage());
                        $erreurs[] = 'Erreur technique lors de l\'ajout. Veuillez réessayer.';
                    }
                }
                break;

            // -----------------------------------------------------------
            case 'modifier':
                $nom = trim($_POST['nom'] ?? '');
                if ($nom === '') {
                    $erreurs[] = 'Le nom de la catégorie est obligatoire.';
                } elseif (mb_strlen($nom) > 255) {
                    $erreurs[] = 'Le nom ne doit pas dépasser 255 caractères.';
                } elseif ($categorieId <= 0) {
                    $erreurs[] = 'Catégorie invalide.';
                } else {
                    try {
                        // Le restaurant_id dans la requête garantit que l'admin
                        // ne peut modifier que les catégories de son propre restaurant
                        $stmt = getDB()->prepare(
                            'UPDATE categories SET nom = ?
                             WHERE id = ? AND restaurant_id = ?'
                        );
                        $stmt->execute([$nom, $categorieId, $restaurantId]);

                        if ($stmt->rowCount() === 0) {
                            $erreurs[] = 'Catégorie introuvable.';
                        } else {
                            header('Location: /admin/categories.php?ok=modifier');
                            exit;
                        }
                    } catch (Exception $e) {
                        error_log('[categories] UPDATE nom : ' . $e->getMessage());
                        $erreurs[] = 'Erreur technique lors de la modification. Veuillez réessayer.';
                    }
                }
                break;

            // -----------------------------------------------------------
            case 'supprimer':
                if ($categorieId <= 0) {
                    $erreurs[] = 'Catégorie invalide.';
                    break;
                }
                try {
                    // Vérifie que la catégorie appartient bien à ce restaurant
                    $stmtCheck = getDB()->prepare(
                        'SELECT id FROM categories WHERE id = ? AND restaurant_id = ?'
                    );
                    $stmtCheck->execute([$categorieId, $restaurantId]);
                    if (!$stmtCheck->fetch()) {
                        $erreurs[] = 'Catégorie introuvable.';
                        break;
                    }

                    // Compte les plats liés avant de tenter la suppression
                    // (la FK RESTRICT bloquerait de toute façon, mais on veut un
                    // message clair plutôt qu'une erreur SQL brute)
                    $stmtPlats = getDB()->prepare(
                        'SELECT COUNT(*) FROM plats WHERE categorie_id = ?'
                    );
                    $stmtPlats->execute([$categorieId]);
                    $nbPlats = (int) $stmtPlats->fetchColumn();

                    if ($nbPlats > 0) {
                        $erreurs[] = sprintf(
                            'Impossible de supprimer cette catégorie : elle contient encore %d plat%s. '
                            . 'Déplacez ou supprimez d\'abord ses plats.',
                            $nbPlats,
                            $nbPlats > 1 ? 's' : ''
                        );
                        break;
                    }

                    $stmtDel = getDB()->prepare(
                        'DELETE FROM categories WHERE id = ? AND restaurant_id = ?'
                    );
                    $stmtDel->execute([$categorieId, $restaurantId]);

                    header('Location: /admin/categories.php?ok=supprimer');
                    exit;
                } catch (Exception $e) {
                    error_log('[categories] DELETE : ' . $e->getMessage());
                    $erreurs[] = 'Erreur technique lors de la suppression. Veuillez réessayer.';
                }
                break;

            // -----------------------------------------------------------
            case 'monter':
            case 'descendre':
                if ($categorieId <= 0) break;
                try {
                    // Charge la catégorie courante (avec vérification d'appartenance)
                    $stmtCurr = getDB()->prepare(
                        'SELECT id, ordre_affichage FROM categories
                         WHERE id = ? AND restaurant_id = ?'
                    );
                    $stmtCurr->execute([$categorieId, $restaurantId]);
                    $curr = $stmtCurr->fetch();
                    if (!$curr) break;

                    // Trouve le voisin immédiat selon la direction
                    if ($action === 'monter') {
                        $stmtVoisin = getDB()->prepare(
                            'SELECT id, ordre_affichage FROM categories
                             WHERE restaurant_id = ? AND ordre_affichage < ?
                             ORDER BY ordre_affichage DESC LIMIT 1'
                        );
                    } else {
                        $stmtVoisin = getDB()->prepare(
                            'SELECT id, ordre_affichage FROM categories
                             WHERE restaurant_id = ? AND ordre_affichage > ?
                             ORDER BY ordre_affichage ASC LIMIT 1'
                        );
                    }
                    $stmtVoisin->execute([$restaurantId, $curr['ordre_affichage']]);
                    $voisin = $stmtVoisin->fetch();

                    if (!$voisin) break; // déjà en première ou dernière position

                    // Échange des ordre_affichage dans une transaction atomique
                    $pdo = getDB();
                    $pdo->beginTransaction();
                    $stmtUpd = $pdo->prepare(
                        'UPDATE categories SET ordre_affichage = ?
                         WHERE id = ? AND restaurant_id = ?'
                    );
                    $stmtUpd->execute([$voisin['ordre_affichage'], $curr['id'],   $restaurantId]);
                    $stmtUpd->execute([$curr['ordre_affichage'],   $voisin['id'], $restaurantId]);
                    $pdo->commit();

                    header('Location: /admin/categories.php');
                    exit;
                } catch (Exception $e) {
                    if (getDB()->inTransaction()) getDB()->rollBack();
                    error_log('[categories] swap ordre : ' . $e->getMessage());
                    $erreurs[] = 'Erreur lors du réordonnancement. Veuillez réessayer.';
                }
                break;
        }
    }
}

// -----------------------------------------------------------------------
// Chargement de la liste des catégories
// -----------------------------------------------------------------------
$categories = [];
try {
    $stmt = getDB()->prepare(
        'SELECT id, nom, ordre_affichage FROM categories
         WHERE restaurant_id = ?
         ORDER BY ordre_affichage ASC'
    );
    $stmt->execute([$restaurantId]);
    $categories = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[categories] SELECT liste : ' . $e->getMessage());
    $erreurs[] = 'Erreur lors du chargement des catégories.';
}

$nbCategories = count($categories);

// -----------------------------------------------------------------------
// Catégorie en cours d'édition (via ?modifier=ID)
// Choix : formulaire en haut de page pré-rempli, activé par un simple
// GET link. PHP-natif, aucun JS, pattern PRG conservé.
// -----------------------------------------------------------------------
$categorieEnEdition = null;
$modifierId = (int) ($_GET['modifier'] ?? 0);
if ($modifierId > 0) {
    try {
        $stmtEdit = getDB()->prepare(
            'SELECT id, nom FROM categories WHERE id = ? AND restaurant_id = ?'
        );
        $stmtEdit->execute([$modifierId, $restaurantId]);
        $categorieEnEdition = $stmtEdit->fetch() ?: null;
    } catch (Exception $e) {
        error_log('[categories] SELECT edit : ' . $e->getMessage());
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catégories — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card">

        <div class="panel-header">
            <h1>Catégories du menu</h1>
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
             Formulaire ajout / modification
             Si ?modifier=ID : affiche le formulaire pré-rempli en mode
             édition. Sinon : affiche le formulaire d'ajout vide.
        ============================================================= -->
        <div class="form-section">
            <?php if ($categorieEnEdition): ?>
                <h2 class="form-section-title">Modifier la catégorie</h2>
                <form method="post" action="/admin/categories.php" class="form-inline-add">
                    <input type="hidden" name="csrf_token"    value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action"        value="modifier">
                    <input type="hidden" name="categorie_id"  value="<?= (int) $categorieEnEdition['id'] ?>">
                    <div class="add-row">
                        <input type="text" name="nom"
                               value="<?= h($categorieEnEdition['nom']) ?>"
                               maxlength="255" required autofocus
                               placeholder="Nom de la catégorie">
                        <button type="submit" class="btn-primary">Enregistrer</button>
                        <a href="/admin/categories.php" class="btn-secondary">Annuler</a>
                    </div>
                </form>
            <?php else: ?>
                <h2 class="form-section-title">Ajouter une catégorie</h2>
                <form method="post" action="/admin/categories.php" class="form-inline-add">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action"     value="ajouter">
                    <div class="add-row">
                        <input type="text" name="nom"
                               maxlength="255" required
                               placeholder="Ex : Entrées, Plats, Desserts…">
                        <button type="submit" class="btn-primary">Ajouter</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- ============================================================
             Liste des catégories
        ============================================================= -->
        <div class="form-section">
            <h2 class="form-section-title">Liste des catégories</h2>

            <?php if ($nbCategories === 0): ?>
                <p class="empty-state">Aucune catégorie pour l'instant. Ajoutez-en une ci-dessus.</p>

            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="col-ordre">Ordre</th>
                            <th>Nom</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $i => $cat):
                            $estPremiere = ($i === 0);
                            $estDerniere = ($i === $nbCategories - 1);
                            $confirmMsg  = json_encode(
                                'Supprimer la catégorie « ' . $cat['nom'] . ' » ? Cette action est irréversible.'
                            );
                        ?>
                        <tr>
                            <td class="col-ordre">
                                <!-- Monter -->
                                <form method="post" action="/admin/categories.php" class="form-btn-inline">
                                    <input type="hidden" name="csrf_token"   value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"       value="monter">
                                    <input type="hidden" name="categorie_id" value="<?= (int) $cat['id'] ?>">
                                    <button type="submit" class="btn-sm btn-order"
                                            <?= $estPremiere ? 'disabled' : '' ?>
                                            title="Monter">↑</button>
                                </form>
                                <!-- Descendre -->
                                <form method="post" action="/admin/categories.php" class="form-btn-inline">
                                    <input type="hidden" name="csrf_token"   value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"       value="descendre">
                                    <input type="hidden" name="categorie_id" value="<?= (int) $cat['id'] ?>">
                                    <button type="submit" class="btn-sm btn-order"
                                            <?= $estDerniere ? 'disabled' : '' ?>
                                            title="Descendre">↓</button>
                                </form>
                            </td>

                            <td class="td-nom"><?= h($cat['nom']) ?></td>

                            <td class="col-actions">
                                <!-- Modifier : GET link, pas de form -->
                                <a href="/admin/categories.php?modifier=<?= (int) $cat['id'] ?>"
                                   class="btn-sm btn-secondary">Modifier</a>

                                <!-- Supprimer -->
                                <form method="post" action="/admin/categories.php"
                                      class="form-btn-inline"
                                      onsubmit="return confirm(<?= $confirmMsg ?>)">
                                    <input type="hidden" name="csrf_token"   value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"       value="supprimer">
                                    <input type="hidden" name="categorie_id" value="<?= (int) $cat['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">Supprimer</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>
</div>
</body>
</html>
