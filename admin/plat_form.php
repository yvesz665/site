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

define('PHOTO_MAX_OCTETS',   5 * 1024 * 1024);
define('PHOTO_MAX_PAR_PLAT', 6);
define('PHOTOS_DIR',         __DIR__ . '/../public/uploads/plats/');

$PHOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

$restaurantId = (int) $_SESSION['restaurant_id'];
$erreurs      = [];

if (!is_dir(PHOTOS_DIR)) {
    mkdir(PHOTOS_DIR, 0755, true);
}

// -----------------------------------------------------------------------
// Mode : création (?id absent) vs modification (?id=X)
// -----------------------------------------------------------------------
$platId      = (int) ($_GET['id'] ?? 0);
$modeEdition = $platId > 0;

// -----------------------------------------------------------------------
// Chargement des catégories du restaurant
// -----------------------------------------------------------------------
$categories = [];
try {
    $stmtCats = getDB()->prepare(
        'SELECT id, nom FROM categories WHERE restaurant_id = ? ORDER BY ordre_affichage ASC'
    );
    $stmtCats->execute([$restaurantId]);
    $categories = $stmtCats->fetchAll();
} catch (Exception $e) {
    error_log('[plat_form] SELECT categories : ' . $e->getMessage());
    $erreurs[] = 'Erreur lors du chargement des catégories.';
}

// -----------------------------------------------------------------------
// En mode édition : charge le plat et vérifie l'appartenance
// -----------------------------------------------------------------------
$plat   = null;
$photos = [];

if ($modeEdition) {
    try {
        // La jointure garantit que ce plat appartient bien au restaurant de la session
        $stmtPlat = getDB()->prepare(
            'SELECT p.id, p.nom, p.description, p.prix, p.categorie_id, p.disponible
             FROM plats p
             INNER JOIN categories c ON c.id = p.categorie_id
             WHERE p.id = ? AND c.restaurant_id = ?'
        );
        $stmtPlat->execute([$platId, $restaurantId]);
        $plat = $stmtPlat->fetch() ?: null;

        if ($plat === null) {
            header('Location: /admin/plats.php');
            exit;
        }

        $stmtPhotos = getDB()->prepare(
            'SELECT id, photo_path, ordre_affichage FROM plat_photos
             WHERE plat_id = ? ORDER BY ordre_affichage ASC'
        );
        $stmtPhotos->execute([$platId]);
        $photos = $stmtPhotos->fetchAll();
    } catch (Exception $e) {
        error_log('[plat_form] SELECT plat/photos : ' . $e->getMessage());
        $erreurs[] = 'Erreur lors du chargement du plat.';
    }
}

// Valeurs du formulaire — initialisées depuis la BD (édition) ou vides (création)
$vals = [
    'nom'          => $modeEdition && $plat ? $plat['nom']                 : '',
    'description'  => $modeEdition && $plat ? ($plat['description'] ?? '') : '',
    'prix'         => $modeEdition && $plat ? number_format((float) $plat['prix'], 2, '.', '') : '',
    'categorie_id' => $modeEdition && $plat ? (int) $plat['categorie_id']  : 0,
    'disponible'   => $modeEdition && $plat ? (int) $plat['disponible']    : 1,
];

// -----------------------------------------------------------------------
// Messages de succès (Pattern PRG)
// -----------------------------------------------------------------------
$messagesOk = [
    'created'       => 'Plat créé avec succès.',
    'updated'       => 'Plat modifié avec succès.',
    'photo_added'   => 'Photo ajoutée.',
    'photo_deleted' => 'Photo supprimée.',
];
$succes = $messagesOk[$_GET['ok'] ?? ''] ?? '';

// -----------------------------------------------------------------------
// Traitement POST
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {

            // -----------------------------------------------------------
            case 'save_plat':
                $nomSaisi        = trim($_POST['nom']         ?? '');
                $descSaisie      = trim($_POST['description'] ?? '');
                $prixBrut        = trim($_POST['prix']        ?? '');
                $prixSaisi       = str_replace(',', '.', $prixBrut);
                $categorieSaisie = (int) ($_POST['categorie_id'] ?? 0);
                $disponible      = isset($_POST['disponible']) ? 1 : 0;

                if ($nomSaisi === '') {
                    $erreurs[] = 'Le nom du plat est obligatoire.';
                } elseif (mb_strlen($nomSaisi) > 255) {
                    $erreurs[] = 'Le nom ne doit pas dépasser 255 caractères.';
                }

                $prixValide = null;
                if ($prixSaisi === '') {
                    $erreurs[] = 'Le prix est obligatoire.';
                } elseif (!is_numeric($prixSaisi) || (float) $prixSaisi < 0) {
                    $erreurs[] = 'Le prix doit être un nombre positif (ex : 12.50).';
                } else {
                    $prixValide = round((float) $prixSaisi, 2);
                }

                if ($categorieSaisie <= 0) {
                    $erreurs[] = 'Veuillez sélectionner une catégorie.';
                } else {
                    $stmtCatChk = getDB()->prepare(
                        'SELECT id FROM categories WHERE id = ? AND restaurant_id = ?'
                    );
                    $stmtCatChk->execute([$categorieSaisie, $restaurantId]);
                    if (!$stmtCatChk->fetch()) {
                        $erreurs[] = 'Catégorie invalide.';
                        $categorieSaisie = 0;
                    }
                }

                // Upload photo (mode création uniquement, facultatif)
                $cheminPhoto     = null;
                $tmpPhotoFichier = null;
                $nomFichierPhoto = null;

                if (!$modeEdition
                    && isset($_FILES['photo'])
                    && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE
                ) {
                    $file = $_FILES['photo'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $erreurs[] = 'Erreur lors du transfert de la photo (code ' . (int) $file['error'] . ').';
                    } elseif ($file['size'] > PHOTO_MAX_OCTETS) {
                        $erreurs[] = 'La photo ne doit pas dépasser 5 Mo.';
                    } else {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime  = $finfo->file($file['tmp_name']);
                        if (!isset($PHOTO_MIMES[$mime])) {
                            $erreurs[] = 'Format non autorisé. Utilisez JPEG, PNG ou WebP.';
                        } else {
                            $ext             = $PHOTO_MIMES[$mime];
                            $nomFichierPhoto = bin2hex(random_bytes(12)) . '.' . $ext;
                            $cheminPhoto     = 'plats/' . $nomFichierPhoto;
                            $tmpPhotoFichier = $file['tmp_name'];
                        }
                    }
                }

                if (empty($erreurs)) {
                    try {
                        $pdo = getDB();

                        if (!$modeEdition) {
                            // --- Création ---
                            $pdo->beginTransaction();

                            $stmtIns = $pdo->prepare(
                                'INSERT INTO plats (categorie_id, nom, description, prix, disponible)
                                 VALUES (?, ?, ?, ?, ?)'
                            );
                            $stmtIns->execute([
                                $categorieSaisie,
                                $nomSaisi,
                                $descSaisie !== '' ? $descSaisie : null,
                                $prixValide,
                                $disponible,
                            ]);
                            $newId = (int) $pdo->lastInsertId();

                            if ($cheminPhoto !== null && $tmpPhotoFichier !== null) {
                                if (!move_uploaded_file($tmpPhotoFichier, PHOTOS_DIR . $nomFichierPhoto)) {
                                    throw new RuntimeException('Impossible de sauvegarder la photo.');
                                }
                                $stmtPhoto = $pdo->prepare(
                                    'INSERT INTO plat_photos (plat_id, photo_path, ordre_affichage) VALUES (?, ?, 1)'
                                );
                                $stmtPhoto->execute([$newId, $cheminPhoto]);
                            }

                            $pdo->commit();
                            header('Location: /admin/plat_form.php?id=' . $newId . '&ok=created');
                            exit;

                        } else {
                            // --- Modification — double vérification ownership ---
                            $stmtOwn = $pdo->prepare(
                                'SELECT p.id FROM plats p
                                 INNER JOIN categories c ON c.id = p.categorie_id
                                 WHERE p.id = ? AND c.restaurant_id = ?'
                            );
                            $stmtOwn->execute([$platId, $restaurantId]);
                            if (!$stmtOwn->fetch()) {
                                $erreurs[] = 'Plat introuvable.';
                                break;
                            }

                            $stmtUpd = $pdo->prepare(
                                'UPDATE plats
                                 SET categorie_id = ?, nom = ?, description = ?, prix = ?, disponible = ?
                                 WHERE id = ?'
                            );
                            $stmtUpd->execute([
                                $categorieSaisie,
                                $nomSaisi,
                                $descSaisie !== '' ? $descSaisie : null,
                                $prixValide,
                                $disponible,
                                $platId,
                            ]);

                            header('Location: /admin/plat_form.php?id=' . $platId . '&ok=updated');
                            exit;
                        }
                    } catch (Exception $e) {
                        if (isset($pdo) && $pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        // Nettoyage du fichier si le move a réussi mais l'INSERT DB a échoué
                        if (isset($nomFichierPhoto) && $nomFichierPhoto !== null
                            && file_exists(PHOTOS_DIR . $nomFichierPhoto)) {
                            @unlink(PHOTOS_DIR . $nomFichierPhoto);
                        }
                        error_log('[plat_form] save_plat : ' . $e->getMessage());
                        $erreurs[] = 'Erreur technique. Veuillez réessayer.';
                    }
                }

                // Conserve les valeurs saisies pour réaffichage en cas d'erreur
                $vals = [
                    'nom'          => $nomSaisi,
                    'description'  => $descSaisie,
                    'prix'         => $prixBrut,
                    'categorie_id' => $categorieSaisie,
                    'disponible'   => $disponible,
                ];
                break;

            // -----------------------------------------------------------
            case 'add_photo':
                if (!$modeEdition || $plat === null) break;

                $nomFichierUploade = null; // pour nettoyage en cas d'erreur DB post-upload

                try {
                    // Compte en base (pas de confiance sur $photos chargé en début de script)
                    $stmtCnt = getDB()->prepare('SELECT COUNT(*) FROM plat_photos WHERE plat_id = ?');
                    $stmtCnt->execute([$platId]);
                    $nbActuel = (int) $stmtCnt->fetchColumn();

                    if ($nbActuel >= PHOTO_MAX_PAR_PLAT) {
                        $erreurs[] = 'Limite de ' . PHOTO_MAX_PAR_PLAT . ' photos atteinte.';
                        break;
                    }

                    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
                        $erreurs[] = 'Veuillez sélectionner une photo.';
                        break;
                    }

                    $file = $_FILES['photo'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        $erreurs[] = 'Erreur lors du transfert de la photo (code ' . (int) $file['error'] . ').';
                        break;
                    }
                    if ($file['size'] > PHOTO_MAX_OCTETS) {
                        $erreurs[] = 'La photo ne doit pas dépasser 5 Mo.';
                        break;
                    }

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($file['tmp_name']);
                    if (!isset($PHOTO_MIMES[$mime])) {
                        $erreurs[] = 'Format non autorisé. Utilisez JPEG, PNG ou WebP.';
                        break;
                    }

                    $ext               = $PHOTO_MIMES[$mime];
                    $nomFichierUploade = bin2hex(random_bytes(12)) . '.' . $ext;
                    $cheminPhoto       = 'plats/' . $nomFichierUploade;

                    if (!move_uploaded_file($file['tmp_name'], PHOTOS_DIR . $nomFichierUploade)) {
                        $erreurs[] = 'Impossible de sauvegarder la photo. Vérifiez les droits du dossier.';
                        break;
                    }

                    $stmtOrd = getDB()->prepare(
                        'SELECT COALESCE(MAX(ordre_affichage), 0) + 1 FROM plat_photos WHERE plat_id = ?'
                    );
                    $stmtOrd->execute([$platId]);
                    $prochainOrdre = (int) $stmtOrd->fetchColumn();

                    $stmtIns = getDB()->prepare(
                        'INSERT INTO plat_photos (plat_id, photo_path, ordre_affichage) VALUES (?, ?, ?)'
                    );
                    $stmtIns->execute([$platId, $cheminPhoto, $prochainOrdre]);

                    header('Location: /admin/plat_form.php?id=' . $platId . '&ok=photo_added');
                    exit;
                } catch (Exception $e) {
                    // Si l'upload fichier a réussi mais la DB a échoué, supprime l'orphelin
                    if ($nomFichierUploade !== null && file_exists(PHOTOS_DIR . $nomFichierUploade)) {
                        @unlink(PHOTOS_DIR . $nomFichierUploade);
                    }
                    error_log('[plat_form] add_photo : ' . $e->getMessage());
                    $erreurs[] = 'Erreur technique lors de l\'ajout de la photo.';
                }
                break;

            // -----------------------------------------------------------
            case 'delete_photo':
                if (!$modeEdition || $plat === null) break;

                $photoId = (int) ($_POST['photo_id'] ?? 0);
                if ($photoId <= 0) break;

                try {
                    // Vérifie que la photo appartient bien à ce plat
                    // (le plat est déjà vérifié comme appartenant au restaurant en début de script)
                    $stmtChk = getDB()->prepare(
                        'SELECT id, photo_path FROM plat_photos WHERE id = ? AND plat_id = ?'
                    );
                    $stmtChk->execute([$photoId, $platId]);
                    $photoRow = $stmtChk->fetch();

                    if (!$photoRow) {
                        $erreurs[] = 'Photo introuvable.';
                        break;
                    }

                    $stmtDel = getDB()->prepare('DELETE FROM plat_photos WHERE id = ?');
                    $stmtDel->execute([$photoId]);

                    $baseUploads = realpath(__DIR__ . '/../public/uploads');
                    $cheminFich  = realpath(__DIR__ . '/../public/uploads/' . $photoRow['photo_path']);
                    if ($cheminFich && $baseUploads && strpos($cheminFich, $baseUploads) === 0) {
                        @unlink($cheminFich);
                    }

                    header('Location: /admin/plat_form.php?id=' . $platId . '&ok=photo_deleted');
                    exit;
                } catch (Exception $e) {
                    error_log('[plat_form] delete_photo : ' . $e->getMessage());
                    $erreurs[] = 'Erreur technique lors de la suppression de la photo.';
                }
                break;

            // -----------------------------------------------------------
            case 'photo_monter':
            case 'photo_descendre':
                if (!$modeEdition || $plat === null) break;

                $photoId = (int) ($_POST['photo_id'] ?? 0);
                if ($photoId <= 0) break;

                try {
                    $stmtCurr = getDB()->prepare(
                        'SELECT id, ordre_affichage FROM plat_photos WHERE id = ? AND plat_id = ?'
                    );
                    $stmtCurr->execute([$photoId, $platId]);
                    $curr = $stmtCurr->fetch();
                    if (!$curr) break;

                    if ($action === 'photo_monter') {
                        $stmtVoisin = getDB()->prepare(
                            'SELECT id, ordre_affichage FROM plat_photos
                             WHERE plat_id = ? AND ordre_affichage < ?
                             ORDER BY ordre_affichage DESC LIMIT 1'
                        );
                    } else {
                        $stmtVoisin = getDB()->prepare(
                            'SELECT id, ordre_affichage FROM plat_photos
                             WHERE plat_id = ? AND ordre_affichage > ?
                             ORDER BY ordre_affichage ASC LIMIT 1'
                        );
                    }
                    $stmtVoisin->execute([$platId, $curr['ordre_affichage']]);
                    $voisin = $stmtVoisin->fetch();
                    if (!$voisin) break;

                    $pdo = getDB();
                    $pdo->beginTransaction();
                    $stmtUpd = $pdo->prepare('UPDATE plat_photos SET ordre_affichage = ? WHERE id = ?');
                    $stmtUpd->execute([$voisin['ordre_affichage'], $curr['id']]);
                    $stmtUpd->execute([$curr['ordre_affichage'],   $voisin['id']]);
                    $pdo->commit();

                    header('Location: /admin/plat_form.php?id=' . $platId);
                    exit;
                } catch (Exception $e) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[plat_form] photo reorder : ' . $e->getMessage());
                    $erreurs[] = 'Erreur lors du réordonnancement.';
                }
                break;
        }
    }
}

// Recharge les photos si on reste sur la page (erreur POST)
if ($modeEdition && $plat !== null && !empty($erreurs)) {
    try {
        $stmtPhotos = getDB()->prepare(
            'SELECT id, photo_path, ordre_affichage FROM plat_photos
             WHERE plat_id = ? ORDER BY ordre_affichage ASC'
        );
        $stmtPhotos->execute([$platId]);
        $photos = $stmtPhotos->fetchAll();
    } catch (Exception $e) {
        error_log('[plat_form] reload photos : ' . $e->getMessage());
    }
}

$nbPhotos   = count($photos);
$csrfToken  = generateCsrfToken();
$formAction = '/admin/plat_form.php' . ($modeEdition ? '?id=' . $platId : '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modeEdition ? 'Modifier un plat' : 'Ajouter un plat' ?> — Administration</title>
    <link rel="stylesheet" href="/public/css/admin.css">
</head>
<body>
<div class="panel-wrapper">
    <div class="panel-card panel-card-lg">

        <div class="panel-header">
            <h1><?= $modeEdition ? 'Modifier : ' . h((string) ($plat['nom'] ?? '')) : 'Ajouter un plat' ?></h1>
            <a href="/admin/plats.php" class="lien-retour">← Retour aux plats</a>
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

        <?php if (empty($categories)): ?>

            <div class="alert alert-info">
                Vous devez d'abord <a href="/admin/categories.php">créer au moins une catégorie</a>
                avant de pouvoir ajouter un plat.
            </div>

        <?php else: ?>

        <!-- ============================================================
             Section 1 : Informations du plat
        ============================================================= -->
        <div class="form-section">
            <h2 class="form-section-title">Informations du plat</h2>

            <form method="post" action="<?= h($formAction) ?>" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action"     value="save_plat">

                <div class="form-group">
                    <label for="nom">
                        Nom du plat <span class="required">*</span>
                    </label>
                    <input type="text" id="nom" name="nom"
                           value="<?= h($vals['nom']) ?>"
                           maxlength="255" required autofocus
                           placeholder="Ex : Poulet rôti aux herbes">
                </div>

                <div class="form-group">
                    <label for="description">
                        Description <span class="hint">(facultative)</span>
                    </label>
                    <textarea id="description" name="description"
                              rows="3"
                              placeholder="Ingrédients, particularités, allergènes…"><?= h($vals['description']) ?></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="prix">
                            Prix <span class="required">*</span>
                            <span class="hint">(€, ex : 12.50)</span>
                        </label>
                        <input type="text" id="prix" name="prix"
                               value="<?= h($vals['prix']) ?>"
                               inputmode="decimal"
                               placeholder="12.50" required>
                    </div>

                    <div class="form-group">
                        <label for="categorie_id">
                            Catégorie <span class="required">*</span>
                        </label>
                        <select id="categorie_id" name="categorie_id" required>
                            <option value="">— Choisir —</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int) $cat['id'] ?>"
                                        <?= $vals['categorie_id'] === (int) $cat['id'] ? 'selected' : '' ?>>
                                    <?= h($cat['nom']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="label-checkbox">
                        <input type="checkbox" name="disponible" value="1"
                               <?= $vals['disponible'] ? 'checked' : '' ?>>
                        Disponible à la commande
                    </label>
                    <p class="field-help">
                        Si décoché, le plat est masqué sur le menu public mais conservé dans votre liste.
                    </p>
                </div>

                <?php if (!$modeEdition): ?>
                <div class="form-group">
                    <label for="photo">
                        Photo principale <span class="hint">(facultative — JPEG, PNG, WebP, max 5 Mo)</span>
                    </label>
                    <input type="file" id="photo" name="photo"
                           accept="image/jpeg,image/png,image/webp">
                    <p class="field-help">Vous pourrez ajouter d'autres photos après la création du plat.</p>
                </div>
                <?php endif; ?>

                <div class="form-actions">
                    <p class="required-note"><span class="required">*</span> Champs obligatoires</p>
                    <button type="submit" class="btn-primary">
                        <?= $modeEdition ? 'Enregistrer les modifications' : 'Créer le plat' ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($modeEdition): ?>
        <!-- ============================================================
             Section 2 : Galerie de photos
        ============================================================= -->
        <div class="form-section">
            <h2 class="form-section-title">
                Galerie de photos
                <span class="hint">
                    (<?= $nbPhotos ?>/<?= PHOTO_MAX_PAR_PLAT ?> photo<?= $nbPhotos !== 1 ? 's' : '' ?> —
                    la première est affichée comme vignette sur le menu)
                </span>
            </h2>

            <?php if (!empty($photos)): ?>
                <div class="photo-gallery">
                    <?php foreach ($photos as $i => $photo):
                        $estPremiere     = ($i === 0);
                        $estDerniere     = ($i === $nbPhotos - 1);
                        $confirmDelPhoto = json_encode('Supprimer cette photo ?');
                    ?>
                        <div class="photo-item <?= $estPremiere ? 'photo-principale' : '' ?>">
                            <div class="photo-preview-wrap">
                                <img src="/public/uploads/<?= h($photo['photo_path']) ?>"
                                     alt="Photo <?= $i + 1 ?>"
                                     class="photo-preview">
                                <?php if ($estPremiere): ?>
                                    <span class="badge-principale">Principale</span>
                                <?php endif; ?>
                            </div>
                            <div class="photo-actions">
                                <form method="post" action="/admin/plat_form.php?id=<?= $platId ?>"
                                      class="form-btn-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"     value="photo_monter">
                                    <input type="hidden" name="photo_id"   value="<?= (int) $photo['id'] ?>">
                                    <button type="submit" class="btn-sm btn-order"
                                            <?= $estPremiere ? 'disabled' : '' ?>
                                            title="Monter (déplacer vers la gauche / avant)">↑</button>
                                </form>
                                <form method="post" action="/admin/plat_form.php?id=<?= $platId ?>"
                                      class="form-btn-inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"     value="photo_descendre">
                                    <input type="hidden" name="photo_id"   value="<?= (int) $photo['id'] ?>">
                                    <button type="submit" class="btn-sm btn-order"
                                            <?= $estDerniere ? 'disabled' : '' ?>
                                            title="Descendre (déplacer vers la droite / après)">↓</button>
                                </form>
                                <form method="post" action="/admin/plat_form.php?id=<?= $platId ?>"
                                      class="form-btn-inline"
                                      onsubmit="return confirm(<?= $confirmDelPhoto ?>)">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action"     value="delete_photo">
                                    <input type="hidden" name="photo_id"   value="<?= (int) $photo['id'] ?>">
                                    <button type="submit" class="btn-sm btn-danger">Supprimer</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="empty-state">Aucune photo. Ajoutez la photo principale ci-dessous.</p>
            <?php endif; ?>

            <?php if ($nbPhotos < PHOTO_MAX_PAR_PLAT): ?>
                <div class="add-photo-form">
                    <h3 class="form-section-title add-photo-title">
                        <?= empty($photos) ? 'Ajouter la photo principale' : 'Ajouter une photo' ?>
                    </h3>
                    <form method="post" action="/admin/plat_form.php?id=<?= $platId ?>"
                          enctype="multipart/form-data" class="add-photo-row">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action"     value="add_photo">
                        <input type="file" name="photo" id="add_photo"
                               accept="image/jpeg,image/png,image/webp" required>
                        <p class="field-help">JPEG, PNG ou WebP — 5 Mo maximum</p>
                        <div>
                            <button type="submit" class="btn-primary btn-add-photo">Ajouter la photo</button>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <p class="field-help" style="margin-top:1rem;">
                    Limite de <?= PHOTO_MAX_PAR_PLAT ?> photos atteinte.
                    Supprimez une photo pour en ajouter une nouvelle.
                </p>
            <?php endif; ?>
        </div>
        <?php endif; /* $modeEdition */ ?>

        <?php endif; /* empty($categories) */ ?>

    </div>
</div>
</body>
</html>
