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

$messagesOk = [
    'toggle'   => 'Disponibilité mise à jour.',
    'supprime' => 'Plat supprimé.',
];
$succes = $messagesOk[$_GET['ok'] ?? ''] ?? '';

$filtreCategorie = (int) ($_GET['categorie_id'] ?? 0);

// -----------------------------------------------------------------------
// Traitement POST
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action']                  ?? '';
        $platId = (int) ($_POST['plat_id']          ?? 0);
        $filtre = (int) ($_POST['filtre_categorie'] ?? 0);

        $base = '/admin/plats.php' . ($filtre > 0 ? '?categorie_id=' . $filtre : '');
        $sep  = $filtre > 0 ? '&' : '?';

        switch ($action) {

            // -----------------------------------------------------------
            case 'toggle_disponible':
                try {
                    // Vérifie l'appartenance via jointure (chaîne plat → catégorie → restaurant)
                    $stmt = getDB()->prepare(
                        'SELECT p.id, p.disponible FROM plats p
                         INNER JOIN categories c ON c.id = p.categorie_id
                         WHERE p.id = ? AND c.restaurant_id = ?'
                    );
                    $stmt->execute([$platId, $restaurantId]);
                    $row = $stmt->fetch();

                    if ($row) {
                        $stmtUpd = getDB()->prepare('UPDATE plats SET disponible = ? WHERE id = ?');
                        $stmtUpd->execute([$row['disponible'] ? 0 : 1, $platId]);
                        header('Location: ' . $base . $sep . 'ok=toggle');
                        exit;
                    }
                    $erreurs[] = 'Plat introuvable.';
                } catch (Exception $e) {
                    error_log('[plats] toggle_disponible : ' . $e->getMessage());
                    $erreurs[] = 'Erreur technique. Veuillez réessayer.';
                }
                break;

            // -----------------------------------------------------------
            case 'supprimer':
                try {
                    // Vérifie appartenance via jointure (pas de confiance aveugle sur plat_id)
                    $stmtOwn = getDB()->prepare(
                        'SELECT p.id FROM plats p
                         INNER JOIN categories c ON c.id = p.categorie_id
                         WHERE p.id = ? AND c.restaurant_id = ?'
                    );
                    $stmtOwn->execute([$platId, $restaurantId]);
                    if (!$stmtOwn->fetch()) {
                        $erreurs[] = 'Plat introuvable.';
                        break;
                    }

                    // Préempt la FK RESTRICT avec un message clair
                    $stmtCmd = getDB()->prepare(
                        'SELECT COUNT(*) FROM commande_lignes WHERE plat_id = ?'
                    );
                    $stmtCmd->execute([$platId]);
                    $nbCommandes = (int) $stmtCmd->fetchColumn();

                    if ($nbCommandes > 0) {
                        $erreurs[] = sprintf(
                            'Impossible de supprimer ce plat : il figure dans %d commande%s. '
                            . 'Désactivez-le plutôt (disponible = non).',
                            $nbCommandes,
                            $nbCommandes > 1 ? 's' : ''
                        );
                        break;
                    }

                    // Récupère les chemins de photos avant la suppression — la FK CASCADE nettoie
                    // les lignes plat_photos mais ne supprime pas les fichiers sur le disque
                    $stmtPhotos = getDB()->prepare(
                        'SELECT photo_path FROM plat_photos WHERE plat_id = ?'
                    );
                    $stmtPhotos->execute([$platId]);
                    $photosASupprimer = $stmtPhotos->fetchAll();

                    $stmtDel = getDB()->prepare('DELETE FROM plats WHERE id = ?');
                    $stmtDel->execute([$platId]);

                    $baseUploads = realpath(__DIR__ . '/../public/uploads');
                    foreach ($photosASupprimer as $p) {
                        $ch = realpath(__DIR__ . '/../public/uploads/' . $p['photo_path']);
                        if ($ch && $baseUploads && strpos($ch, $baseUploads) === 0) {
                            @unlink($ch);
                        }
                    }

                    header('Location: ' . $base . $sep . 'ok=supprime');
                    exit;
                } catch (Exception $e) {
                    error_log('[plats] supprimer : ' . $e->getMessage());
                    $erreurs[] = 'Erreur technique lors de la suppression. Veuillez réessayer.';
                }
                break;
        }
    }
}

// -----------------------------------------------------------------------
// Chargement des catégories (pour le filtre)
// -----------------------------------------------------------------------
$categories = [];
try {
    $stmtCats = getDB()->prepare(
        'SELECT id, nom FROM categories WHERE restaurant_id = ? ORDER BY ordre_affichage ASC'
    );
    $stmtCats->execute([$restaurantId]);
    $categories = $stmtCats->fetchAll();
} catch (Exception $e) {
    error_log('[plats] SELECT categories : ' . $e->getMessage());
}

// -----------------------------------------------------------------------
// Chargement des plats
// -----------------------------------------------------------------------
$plats = [];
try {
    $sql = 'SELECT p.id, p.nom, p.prix, p.disponible, p.categorie_id,
                   c.nom AS categorie_nom,
                   (SELECT pp.photo_path FROM plat_photos pp
                    WHERE pp.plat_id = p.id
                    ORDER BY pp.ordre_affichage ASC LIMIT 1) AS photo_principale
            FROM plats p
            INNER JOIN categories c ON c.id = p.categorie_id
            WHERE c.restaurant_id = ?';
    $params = [$restaurantId];

    if ($filtreCategorie > 0) {
        $sql     .= ' AND p.categorie_id = ?';
        $params[] = $filtreCategorie;
    }
    $sql .= ' ORDER BY c.ordre_affichage ASC, p.nom ASC';

    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $plats = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[plats] SELECT plats : ' . $e->getMessage());
    $erreurs[] = 'Erreur lors du chargement des plats.';
}

$nbPlats   = count($plats);
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plats — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card panel-card-lg">

        <div class="panel-header">
            <h1>Plats du menu</h1>
            <div class="panel-header-actions">
                <a href="/admin/plat_form.php" class="btn-sm btn-add-plat">+ Ajouter un plat</a>
                <a href="/admin/dashboard.php" class="lien-retour">← Tableau de bord</a>
            </div>
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

        <?php if (!empty($categories)): ?>
            <form method="get" action="/admin/plats.php" class="filtre-form">
                <label for="filtre-cat" class="filtre-label">Catégorie :</label>
                <select id="filtre-cat" name="categorie_id"
                        class="filtre-select" onchange="this.form.submit()">
                    <option value="0">Toutes les catégories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int) $cat['id'] ?>"
                                <?= $filtreCategorie === (int) $cat['id'] ? 'selected' : '' ?>>
                            <?= h($cat['nom']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript>
                    <button type="submit" class="btn-sm btn-secondary">Filtrer</button>
                </noscript>
            </form>
        <?php endif; ?>

        <?php if ($nbPlats === 0): ?>
            <p class="empty-state">
                <?php if ($filtreCategorie > 0): ?>
                    Aucun plat dans cette catégorie.
                    <a href="/admin/plats.php">Voir tous les plats.</a>
                <?php elseif (empty($categories)): ?>
                    Commencez par <a href="/admin/categories.php">créer une catégorie</a>,
                    puis ajoutez vos plats.
                <?php else: ?>
                    Aucun plat pour l'instant.
                    <a href="/admin/plat_form.php">Ajouter votre premier plat.</a>
                <?php endif; ?>
            </p>
        <?php else: ?>
            <table class="admin-table plats-table">
                <thead>
                    <tr>
                        <th class="col-photo">Photo</th>
                        <th>Plat</th>
                        <th class="col-categorie">Catégorie</th>
                        <th class="col-prix">Prix</th>
                        <th class="col-dispo">Dispo</th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plats as $plat):
                        $confirmMsg = json_encode(
                            'Supprimer le plat « ' . $plat['nom'] . ' » ? Cette action est irréversible.'
                        );
                    ?>
                    <tr>
                        <td class="col-photo">
                            <?php if ($plat['photo_principale']): ?>
                                <img src="/public/uploads/<?= h($plat['photo_principale']) ?>"
                                     alt="" class="plat-thumb">
                            <?php else: ?>
                                <div class="plat-thumb-vide"></div>
                            <?php endif; ?>
                        </td>

                        <td class="td-nom"><?= h($plat['nom']) ?></td>

                        <td class="col-categorie"><?= h($plat['categorie_nom']) ?></td>

                        <td class="col-prix"><?= number_format((float) $plat['prix'], 2, ',', ' ') ?> €</td>

                        <td class="col-dispo">
                            <form method="post" action="/admin/plats.php" class="form-btn-inline">
                                <input type="hidden" name="csrf_token"       value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action"           value="toggle_disponible">
                                <input type="hidden" name="plat_id"          value="<?= (int) $plat['id'] ?>">
                                <input type="hidden" name="filtre_categorie" value="<?= $filtreCategorie ?>">
                                <button type="submit"
                                        class="btn-sm badge-toggle <?= $plat['disponible'] ? 'badge-dispo' : 'badge-indispo' ?>"
                                        title="Cliquer pour <?= $plat['disponible'] ? 'désactiver' : 'activer' ?>">
                                    <?= $plat['disponible'] ? 'Oui' : 'Non' ?>
                                </button>
                            </form>
                        </td>

                        <td class="col-actions">
                            <a href="/admin/plat_form.php?id=<?= (int) $plat['id'] ?>"
                               class="btn-sm btn-secondary">Modifier</a>

                            <form method="post" action="/admin/plats.php"
                                  class="form-btn-inline"
                                  onsubmit="return confirm(<?= $confirmMsg ?>)">
                                <input type="hidden" name="csrf_token"       value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="action"           value="supprimer">
                                <input type="hidden" name="plat_id"          value="<?= (int) $plat['id'] ?>">
                                <input type="hidden" name="filtre_categorie" value="<?= $filtreCategorie ?>">
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
</body>
</html>
