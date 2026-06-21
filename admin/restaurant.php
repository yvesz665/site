<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/restaurant_check.php';

requireLogin();

// Fonction d'échappement HTML locale
if (!function_exists('h')) {
    function h(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    }
}

const JOURS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

// Upload : 2 Mo — suffisant pour un logo restaurant haute qualité (PNG/WebP < 1 Mo en pratique),
// raisonnable pour un hébergement mutualisé où les transferts sont limités.
const LOGO_MAX_OCTETS = 2 * 1024 * 1024;
const LOGO_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

$erreurs = [];
$succes  = false;

// -----------------------------------------------------------------------
// Charge ou initialise la ligne restaurant
// -----------------------------------------------------------------------
$restaurant = getRestaurantData();

if ($restaurant === null) {
    try {
        getDB()->exec("INSERT INTO restaurant (nom, couleur_principale) VALUES ('', '#FF6B35')");
        $restaurant = getRestaurantData();
    } catch (Exception $e) {
        error_log('[restaurant] INSERT initial : ' . $e->getMessage());
        $erreurs[] = 'Erreur technique lors de l\'initialisation. Veuillez réessayer.';
    }
}

// -----------------------------------------------------------------------
// Décode les horaires JSON stockés en base, ou applique les défauts
// -----------------------------------------------------------------------
$horaires = [];
if ($restaurant && !empty($restaurant['horaires'])) {
    $decoded = json_decode($restaurant['horaires'], true);
    if (is_array($decoded)) {
        $horaires = $decoded;
    }
}
foreach (JOURS as $jour) {
    if (!isset($horaires[$jour])) {
        // Par défaut : fermé, plages vides mais pré-remplies pour faciliter la saisie
        $horaires[$jour] = ['ouvert' => false, 'debut' => '11:30', 'fin' => '22:00'];
    }
}

// -----------------------------------------------------------------------
// Traitement du formulaire
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $restaurant !== null) {

    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $erreurs[] = 'Jeton de sécurité invalide. Veuillez recharger la page et réessayer.';
    } else {
        $nom       = trim($_POST['nom']       ?? '');
        $adresse   = trim($_POST['adresse']   ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $email     = trim($_POST['email']     ?? '');
        $couleur   = trim($_POST['couleur_principale'] ?? '#FF6B35');

        // Numéro WhatsApp : on ne garde que les chiffres (l'utilisateur peut taper "226 70 00 00 00")
        $whatsappNumero = preg_replace('/\D/', '', trim($_POST['whatsapp_numero'] ?? ''));

        // Validation des champs obligatoires
        if ($nom === '')       $erreurs[] = 'Le nom du restaurant est obligatoire.';
        if ($adresse === '')   $erreurs[] = 'L\'adresse est obligatoire.';
        if ($telephone === '') $erreurs[] = 'Le numéro de téléphone est obligatoire.';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse email n\'est pas valide.';
        }
        // Numéro WhatsApp : optionnel, mais si renseigné doit respecter la plage E.164 (7-15 chiffres)
        if ($whatsappNumero !== '' && (strlen($whatsappNumero) < 7 || strlen($whatsappNumero) > 15)) {
            $erreurs[] = 'Le numéro WhatsApp doit contenir entre 7 et 15 chiffres '
                . '(format international sans +, ex : 22670000000).';
        }
        // Couleur hexadécimale : remet la valeur par défaut si invalide
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $couleur)) {
            $couleur = '#FF6B35';
        }

        // Construction du JSON horaires depuis POST
        $horairesPost = [];
        foreach (JOURS as $jour) {
            $ouvert = !empty($_POST['horaires'][$jour]['ouvert']);
            $debut  = $_POST['horaires'][$jour]['debut'] ?? '11:30';
            $fin    = $_POST['horaires'][$jour]['fin']   ?? '22:00';
            // Garantit le format HH:MM attendu par <input type="time">
            if (!preg_match('/^\d{2}:\d{2}$/', $debut)) $debut = '11:30';
            if (!preg_match('/^\d{2}:\d{2}$/', $fin))   $fin   = '22:00';
            $horairesPost[$jour] = ['ouvert' => $ouvert, 'debut' => $debut, 'fin' => $fin];
        }
        // Permet au formulaire d'afficher les valeurs soumises en cas d'erreur
        $horaires = $horairesPost;

        // -----------------------------------------------------------------------
        // Upload du logo
        // -----------------------------------------------------------------------
        $logoPath = $restaurant['logo_path']; // conserve le logo existant par défaut

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['logo'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $erreurs[] = 'Erreur lors de l\'upload du logo (code ' . (int) $file['error'] . ').';
            } elseif ($file['size'] > LOGO_MAX_OCTETS) {
                $erreurs[] = 'Le logo ne doit pas dépasser 2 Mo.';
            } else {
                // Vérification du type MIME réel via finfo (pas l'extension du nom de fichier,
                // qui peut être falsifiée facilement)
                $finfo    = new finfo(FILEINFO_MIME_TYPE);
                $mimeReel = $finfo->file($file['tmp_name']);

                if (!isset(LOGO_MIMES[$mimeReel])) {
                    $erreurs[] = 'Format de logo non autorisé. Utilisez JPEG, PNG, WebP ou GIF.';
                } else {
                    $ext         = LOGO_MIMES[$mimeReel];
                    $nomFichier  = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destination = __DIR__ . '/../public/uploads/' . $nomFichier;

                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $erreurs[] = 'Impossible de sauvegarder le logo. Vérifiez les droits du dossier public/uploads/.';
                    } else {
                        // Suppression de l'ancien logo avec vérification de chemin
                        // pour éviter toute suppression hors du dossier uploads
                        if ($logoPath) {
                            $ancienChemin  = realpath(__DIR__ . '/../public/' . $logoPath);
                            $dossierUploads = realpath(__DIR__ . '/../public/uploads');
                            if ($ancienChemin && $dossierUploads
                                && strpos($ancienChemin, $dossierUploads) === 0) {
                                @unlink($ancienChemin);
                            }
                        }
                        $logoPath = 'uploads/' . $nomFichier;
                    }
                }
            }
        }

        // -----------------------------------------------------------------------
        // Sauvegarde si aucune erreur
        // -----------------------------------------------------------------------
        if (empty($erreurs)) {
            try {
                $stmt = getDB()->prepare(
                    'UPDATE restaurant
                     SET nom = ?, adresse = ?, telephone = ?, email = ?,
                         couleur_principale = ?, horaires = ?, logo_path = ?,
                         whatsapp_numero = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $nom,
                    $adresse,
                    $telephone,
                    $email !== '' ? $email : null,
                    $couleur,
                    json_encode($horairesPost, JSON_UNESCAPED_UNICODE),
                    $logoPath,
                    $whatsappNumero !== '' ? $whatsappNumero : null,
                    $restaurant['id'],
                ]);

                // Recharge les données depuis la base pour les afficher à jour
                $restaurant = getRestaurantData();
                if ($restaurant && !empty($restaurant['horaires'])) {
                    $decoded = json_decode($restaurant['horaires'], true);
                    if (is_array($decoded)) $horaires = $decoded;
                }

                $succes = true;
            } catch (Exception $e) {
                error_log('[restaurant] UPDATE : ' . $e->getMessage());
                $erreurs[] = 'Erreur technique lors de la sauvegarde. Veuillez réessayer.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
$r         = $restaurant ?? [];

// Valeurs à afficher dans le formulaire (données rechargées depuis la base si succès,
// données POST sinon pour conserver ce que l'utilisateur avait saisi)
$valNom      = h((string) ($r['nom']              ?? ''));
$valAdresse  = h((string) ($r['adresse']          ?? ''));
$valTel      = h((string) ($r['telephone']        ?? ''));
$valEmail    = h((string) ($r['email']            ?? ''));
$valCouleur  = h((string) ($r['couleur_principale'] ?? '#FF6B35'));
$valLogoPath = (string) ($r['logo_path']            ?? '');
$valWhatsapp = h((string) ($r['whatsapp_numero']  ?? ''));

// Tout traitement POST terminé — aucune redirection possible après cette ligne.
// restaurant.php n'appelle pas requireRestaurantConfigured() — la sidebar s'affiche
// même lors de la première configuration du restaurant.
$page_actuelle = 'restaurant';
$titre_page    = 'Fiche restaurant';
require __DIR__ . '/includes/layout_header.php';
?>
<div class="panel-card">

    <div class="panel-header">
        <h1>Fiche du restaurant</h1>
    </div>

    <?php if ($succes): ?>
        <div class="alert alert-success">
            Informations sauvegardées avec succès.
            <?php if (isRestaurantConfigured()): ?>
                <a href="/admin/dashboard.php">Accéder au tableau de bord →</a>
            <?php endif; ?>
        </div>
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

        <?php if (!isRestaurantConfigured() && !$succes): ?>
            <div class="alert alert-info">
                Bienvenue ! Commencez par renseigner les informations de votre restaurant
                avant d'accéder à la gestion de votre menu.
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/restaurant.php"
              enctype="multipart/form-data" novalidate>

            <input type="hidden" name="csrf_token"
                   value="<?= h($csrfToken) ?>">

            <!-- =====================================================
                 Section 1 : Informations générales
            ====================================================== -->
            <div class="form-section">
                <h2 class="form-section-title">Informations générales</h2>

                <div class="form-group">
                    <label for="nom">Nom du restaurant <span class="required">*</span></label>
                    <input type="text" id="nom" name="nom"
                           value="<?= $valNom ?>"
                           maxlength="255" required autofocus>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="telephone">Téléphone <span class="required">*</span></label>
                        <input type="tel" id="telephone" name="telephone"
                               value="<?= $valTel ?>"
                               maxlength="20" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email <span class="hint">optionnel</span></label>
                        <input type="email" id="email" name="email"
                               value="<?= $valEmail ?>"
                               maxlength="255" autocomplete="email">
                    </div>
                </div>

                <div class="form-group">
                    <label for="whatsapp_numero">
                        WhatsApp <span class="hint">optionnel — notifications réservations</span>
                    </label>
                    <input type="tel" id="whatsapp_numero" name="whatsapp_numero"
                           value="<?= $valWhatsapp ?>"
                           maxlength="15"
                           placeholder="22670000000">
                    <p class="field-help">
                        Format international sans le + ni les espaces.
                        Exemple : <strong>22670000000</strong> (Burkina Faso),
                        <strong>33612345678</strong> (France).
                        Ce numéro recevra les notifications de réservation.
                    </p>
                </div>

                <div class="form-group">
                    <label for="adresse">Adresse <span class="required">*</span></label>
                    <input type="text" id="adresse" name="adresse"
                           value="<?= $valAdresse ?>"
                           maxlength="500"
                           placeholder="123 Rue de la Paix, 75001 Paris"
                           required>
                </div>

                <div class="form-group form-group-color">
                    <label for="couleur_principale">Couleur principale</label>
                    <div class="color-picker-wrapper">
                        <input type="color" id="couleur_principale" name="couleur_principale"
                               value="<?= $valCouleur ?>">
                        <span class="color-hint">Utilisée sur le site public (boutons, accents)</span>
                    </div>
                </div>
            </div>

            <!-- =====================================================
                 Section 2 : Logo
            ====================================================== -->
            <div class="form-section">
                <h2 class="form-section-title">Logo <span class="hint">optionnel</span></h2>

                <?php if ($valLogoPath !== ''): ?>
                    <div class="logo-preview">
                        <img src="/public/<?= h($valLogoPath) ?>" alt="Logo actuel">
                        <p class="hint">Logo actuel — téléversez un nouveau fichier pour le remplacer.</p>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="logo">Fichier logo</label>
                    <input type="file" id="logo" name="logo"
                           accept="image/jpeg,image/png,image/webp,image/gif">
                    <p class="field-help">JPEG, PNG, WebP ou GIF — 2 Mo maximum.</p>
                </div>
            </div>

            <!-- =====================================================
                 Section 3 : Horaires
                 Interface jour par jour : cohérente avec le stockage
                 JSON (lundi:{ouvert, debut, fin}) déjà défini dans
                 le schéma. Permet au site public d'afficher
                 "Ouvert aujourd'hui" sans parser du texte libre.
            ====================================================== -->
            <div class="form-section">
                <h2 class="form-section-title">Horaires <span class="hint">optionnel</span></h2>

                <div class="horaires-grid">
                    <div class="horaires-header">
                        <span>Jour</span>
                        <span>Ouvert</span>
                        <span>De</span>
                        <span>À</span>
                    </div>
                    <?php foreach (JOURS as $jour):
                        $ouvert = !empty($horaires[$jour]['ouvert']);
                        $debut  = h((string) ($horaires[$jour]['debut'] ?? '11:30'));
                        $fin    = h((string) ($horaires[$jour]['fin']   ?? '22:00'));
                    ?>
                    <div class="horaires-row">
                        <span class="jour-label"><?= ucfirst($jour) ?></span>

                        <label class="toggle-label">
                            <input type="checkbox"
                                   id="ouvert_<?= $jour ?>"
                                   name="horaires[<?= $jour ?>][ouvert]"
                                   value="1"
                                   <?= $ouvert ? 'checked' : '' ?>
                                   onchange="toggleHoraires('<?= $jour ?>')">
                        </label>

                        <input type="time"
                               id="debut_<?= $jour ?>"
                               name="horaires[<?= $jour ?>][debut]"
                               value="<?= $debut ?>"
                               class="time-input <?= $jour ?>_times"
                               <?= $ouvert ? '' : 'disabled' ?>>

                        <input type="time"
                               id="fin_<?= $jour ?>"
                               name="horaires[<?= $jour ?>][fin]"
                               value="<?= $fin ?>"
                               class="time-input <?= $jour ?>_times"
                               <?= $ouvert ? '' : 'disabled' ?>>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-actions">
                <p class="required-note"><span class="required">*</span> Champs obligatoires</p>
                <button type="submit" class="btn-primary">Enregistrer</button>
            </div>

    </form>
</div>

<script>
function toggleHoraires(jour) {
    document.querySelectorAll('.' + jour + '_times').forEach(function(input) {
        input.disabled = !document.getElementById('ouvert_' + jour).checked;
    });
}
</script>
<?php require __DIR__ . '/includes/layout_footer.php'; ?>
