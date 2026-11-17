<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: index.php");
    exit;
}

// Nettoyage périodique des fichiers
if (shouldCleanFiles()) {
    cleanOldFiles();
}

// Erreurs d'upload éventuelles (dépassement de taille, échec serveur...)
$uploadErrors = isset($_SESSION['upload_errors']) ? $_SESSION['upload_errors'] : array();
unset($_SESSION['upload_errors']);

$uploadLimits = getUploadLimits();
$now = time();

// Liste des fichiers avec leur date d'envoi, leur durée de conservation et leur expiration
$files = array();
$directory = "uploads/";

if (file_exists($directory)) {
    $items = array_filter(scandir($directory), function ($item) {
        // Exclut les entrées système et les fichiers cachés (.htaccess, .expiry.json...)
        return $item !== '.' && $item !== '..' && $item[0] !== '.';
    });
} else {
    $items = array();
}

$meta = loadExpiryMeta($directory);

foreach ($items as $item) {
    $path = $directory . $item;
    if (!is_file($path)) {
        continue;
    }

    $expiry = getFileExpiry($meta, $item, $path);

    // Déjà expiré mais pas encore nettoyé : on ne l'affiche plus
    if ($expiry['expires'] <= $now) {
        continue;
    }

    $files[] = array(
        'name' => $item,
        'time' => filemtime($path),
        'expires' => $expiry['expires'],
        'retention' => $expiry['retention'],
    );
}

// Trier les fichiers par date de modification (plus récent en premier)
usort($files, function ($a, $b) {
    return $b['time'] - $a['time'];
});

// Date d'envoi, à la minute près (pas de secondes qui défilent)
function formatTimeAgo($timestamp)
{
    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return "À l'instant";
    }
    if ($diff < 3600) {
        return "Il y a " . floor($diff / 60) . " min";
    }
    if ($diff < 86400) {
        return "Il y a " . floor($diff / 3600) . " h " . str_pad(floor(($diff % 3600) / 60), 2, '0', STR_PAD_LEFT);
    }
    $days = floor($diff / 86400);
    return "Il y a " . $days . " j";
}

// Temps restant approximatif (même logique que formatRemaining() côté JS)
function formatRemaining($seconds)
{
    if ($seconds <= 60) {
        return "moins d'1 min";
    }
    if ($seconds < 3600) {
        return ceil($seconds / 60) . " min";
    }
    if ($seconds < 3 * 3600) {
        $hours = floor($seconds / 3600);
        $minutes = round(($seconds % 3600) / 60 / 5) * 5;
        if ($minutes >= 60) {
            $hours++;
            $minutes = 0;
        }
        return $hours . " h" . ($minutes > 0 ? " " . str_pad($minutes, 2, '0', STR_PAD_LEFT) : '');
    }
    if ($seconds < 86400) {
        return "~" . round($seconds / 3600) . " h";
    }
    $days = floor($seconds / 86400);
    $hours = round(($seconds % 86400) / 3600);
    if ($hours >= 24) {
        $days++;
        $hours = 0;
    }
    return "~" . $days . " j" . ($hours > 0 ? " " . $hours . " h" : '');
}

// Taille lisible (Ko sous 1 Mo)
function formatSize($bytes)
{
    if ($bytes < 1024 * 1024) {
        return max(1, round($bytes / 1024)) . ' Ko';
    }
    return round($bytes / 1024 / 1024, 1) . ' Mo';
}

// Select custom de durée de conservation
function renderRetentionSelect($id)
{
    $options = RETENTION_OPTIONS;
    $default = DEFAULT_RETENTION;
    ob_start(); ?>
    <div class="tn-select" data-sync="retention">
        <input type="hidden" name="retention" value="<?php echo $default; ?>">
        <button type="button" class="tn-select-trigger" id="<?php echo $id; ?>" aria-haspopup="listbox" aria-expanded="false">
            <span class="tn-select-label">Conserver</span>
            <span class="tn-select-value"><?php echo htmlspecialchars($options[$default]['label']); ?></span>
            <span class="tn-select-icon" aria-hidden="true"></span>
        </button>
        <ul class="tn-select-menu" role="listbox" tabindex="-1" aria-labelledby="<?php echo $id; ?>">
            <?php foreach ($options as $key => $option): ?>
                <li class="tn-select-option" role="option" data-value="<?php echo $key; ?>" aria-selected="<?php echo $key === $default ? 'true' : 'false'; ?>">
                    <?php echo htmlspecialchars($option['label']); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php return ob_get_clean();
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Now</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png" />
</head>

<body>
    <div class="login-container other-container">
        <div class="title-container">
            <img src="assets/logo.png" alt="Logo" class="logo" draggable="false">
            <h2>Transfer Now</h2>
        </div>

        <div class="tn-tabs" role="tablist" data-active="0">
            <span class="tn-tabs-indicator" aria-hidden="true"></span>
            <button class="tn-tab" id="files-tab" type="button" role="tab" aria-selected="true" aria-controls="files">Fichiers</button>
            <button class="tn-tab" id="text-tab" type="button" role="tab" aria-selected="false" aria-controls="text" tabindex="-1">Texte</button>
        </div>

        <!-- Onglet Fichiers -->
        <div class="tn-panel" id="files" role="tabpanel" aria-labelledby="files-tab">
            <?php if (!empty($uploadErrors)): ?>
                <div class="tn-alert">
                    <?php foreach ($uploadErrors as $uploadError): ?>
                        <div><?php echo htmlspecialchars($uploadError); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div id="uploadClientError" class="tn-alert" hidden></div>
            <div class="drop-zone" id="dropZone">
                <form action="upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <p class="drop-zone-title">Glissez-déposez vos fichiers ici</p>
                    <?php echo renderRetentionSelect('retentionFiles'); ?>
                    <div class="tn-file" id="filePicker" style="margin-top: 0.75rem;">
                        <input type="file" name="files[]" id="fileInput" multiple class="tn-visually-hidden">
                        <label for="fileInput" class="tn-btn tn-btn-primary tn-file-trigger">Sélectionner des fichiers</label>
                        <span class="tn-file-summary" id="fileSummary">Envoi automatique dès la sélection</span>
                    </div>
                </form>
                <small class="drop-zone-hint">
                    Max <?php echo round($uploadLimits['file'] / 1024 / 1024, 1); ?> Mo par fichier
                    · <?php echo round($uploadLimits['post'] / 1024 / 1024, 1); ?> Mo au total
                </small>
            </div>
        </div>

        <!-- Onglet Texte -->
        <div class="tn-panel" id="text" role="tabpanel" aria-labelledby="text-tab" hidden>
            <form action="save_text.php" method="POST" id="textForm">
                <div class="tn-textarea-wrap">
                    <textarea class="tn-input tn-textarea" id="textContent" name="content" required placeholder="Collez votre texte ici..."></textarea>
                </div>
                <div class="tn-form-row">
                    <?php echo renderRetentionSelect('retentionText'); ?>
                    <button type="submit" class="tn-btn tn-btn-primary">Enregistrer</button>
                </div>
            </form>
        </div>

        <?php if (count($files) > 0): ?>
            <div class="list-header">
                <h3 class="section-title">Éléments disponibles</h3>
                <form action="clear_all.php" method="POST" id="clearAllForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
                    <button type="submit" class="tn-btn tn-btn-ghost-danger">Tout supprimer</button>
                </form>
            </div>
            <div class="tn-list">
                <?php foreach ($files as $file): ?>
                    <?php
                    $isTextFile = pathinfo($file['name'], PATHINFO_EXTENSION) === 'tnow';
                    $content = $isTextFile ? file_get_contents($directory . $file['name']) : '';
                    $remaining = $file['expires'] - $now;
                    $dataAttrs = 'data-uploaded="' . (int) $file['time'] . '"'
                        . ' data-expires="' . (int) $file['expires'] . '"'
                        . ' data-retention="' . (int) $file['retention'] . '"';
                    $meta = '<span class="file-time">'
                        . '<span class="js-ago">' . formatTimeAgo($file['time']) . '</span>'
                        . ' · Conservé ' . htmlspecialchars(retentionLabel($file['retention']))
                        . ' · Reste <span class="js-remaining">' . formatRemaining($remaining) . '</span>'
                        . '</span>';
                    ?>
                    <?php if ($isTextFile): ?>
                        <div class="tn-item text-link" role="button" tabindex="0" draggable="false"
                            data-content="<?php echo htmlspecialchars($content); ?>" <?php echo $dataAttrs; ?>>
                            <div class="file-info">
                                <span class="filename"><?php echo htmlspecialchars($content); ?></span>
                                <?php echo $meta; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="download.php?file=<?php echo urlencode($file['name']); ?>" target="_blank" draggable="false"
                            class="tn-item" <?php echo $dataAttrs; ?>>
                            <div class="file-info">
                                <span class="filename"><?php echo htmlspecialchars($file['name']); ?></span>
                                <?php echo $meta; ?>
                            </div>
                            <span class="tn-badge"><?php echo formatSize(filesize($directory . $file['name'])); ?></span>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form action="logout.php" method="POST" class="card-footer">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken()); ?>">
            <button type="submit" class="tn-link-btn">Se déconnecter</button>
        </form>
    </div>

    <!-- Modale de confirmation "Tout supprimer" -->
    <div class="tn-modal" id="clearAllModal" role="dialog" aria-modal="true" aria-labelledby="clearAllTitle" aria-hidden="true">
        <div class="tn-modal-backdrop" data-modal-close></div>
        <div class="tn-modal-dialog">
            <h4 class="tn-modal-title" id="clearAllTitle">Tout supprimer ?</h4>
            <p class="tn-modal-text">Tous les éléments seront définitivement effacés du serveur. Cette action est irréversible.</p>
            <div class="tn-modal-actions">
                <button type="button" class="tn-btn tn-btn-secondary" data-modal-close>Annuler</button>
                <button type="button" class="tn-btn tn-btn-danger" id="confirmClearAll">Supprimer</button>
            </div>
        </div>
    </div>

    <script src="assets/ui.js"></script>

    <script>
        // Limites d'upload, synchronisées avec la config PHP du serveur (upload_max_filesize / post_max_size)
        const MAX_FILE_SIZE = <?php echo (int) $uploadLimits['file']; ?>;
        const MAX_TOTAL_SIZE = <?php echo (int) $uploadLimits['post']; ?>;
        // Marge de sécurité pour les en-têtes multipart et les autres champs du formulaire
        const UPLOAD_SAFETY_MARGIN = 64 * 1024;
        // Décalage entre l'horloge du serveur et celle du navigateur
        const CLOCK_OFFSET = <?php echo $now; ?> * 1000 - Date.now();

        function formatMB(bytes) {
            return (bytes / 1024 / 1024).toFixed(1) + ' Mo';
        }

        // --- Onglets ---------------------------------------------------------
        TN.tabs(document.querySelector('.tn-tabs'));

        // --- Selects de durée (synchronisés entre les deux formulaires) ------
        document.querySelectorAll('.tn-select').forEach(el => TN.select(el));

        // --- Envoi de fichiers -----------------------------------------------
        const uploadForm = document.getElementById('uploadForm');
        const uploadClientError = document.getElementById('uploadClientError');
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const filePicker = document.getElementById('filePicker');
        const fileSummary = document.getElementById('fileSummary');
        const defaultSummary = fileSummary.textContent;

        function showUploadError(message) {
            uploadClientError.textContent = message;
            uploadClientError.hidden = false;
        }

        function clearUploadError() {
            uploadClientError.hidden = true;
            uploadClientError.textContent = '';
        }

        // Vérifie que la sélection respecte les limites du serveur avant tout envoi
        function validateFiles(fileList) {
            const files = Array.from(fileList);
            if (files.length === 0) {
                return "Sélectionnez au moins un fichier avant d'envoyer.";
            }

            const tooLarge = files.find(f => f.size > MAX_FILE_SIZE);
            if (tooLarge) {
                return `"${tooLarge.name}" (${formatMB(tooLarge.size)}) dépasse la taille maximale autorisée par fichier (${formatMB(MAX_FILE_SIZE)}).`;
            }

            const totalSize = files.reduce((sum, f) => sum + f.size, 0);
            if (totalSize > MAX_TOTAL_SIZE - UPLOAD_SAFETY_MARGIN) {
                return `Le volume total sélectionné (${formatMB(totalSize)}) dépasse la limite du serveur (${formatMB(MAX_TOTAL_SIZE)}). Envoyez moins de fichiers à la fois.`;
            }

            return null;
        }

        // Valide puis envoie une sélection de fichiers ; sinon affiche l'erreur et n'envoie rien
        function trySubmitFiles(fileList) {
            const error = validateFiles(fileList);
            if (error) {
                fileInput.value = '';
                fileSummary.textContent = defaultSummary;
                filePicker.classList.remove('has-files');
                showUploadError(error);
                return;
            }
            clearUploadError();
            if (fileInput.files !== fileList) {
                fileInput.files = fileList;
            }
            const count = fileList.length;
            fileSummary.textContent = `Envoi de ${count > 1 ? count + ' fichiers' : fileList[0].name}…`;
            filePicker.classList.add('has-files');
            uploadForm.submit();
        }

        // Sélection via le bouton : envoi immédiat, comme le glisser-déposer
        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) {
                trySubmitFiles(fileInput.files);
            }
        });

        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            trySubmitFiles(e.dataTransfer.files);
        });

        // --- Zone de texte : hauteur auto + scrollbar custom ------------------
        const textContent = document.getElementById('textContent');
        function autoGrow() {
            textContent.style.height = 'auto';
            textContent.style.height = textContent.scrollHeight + 3 + 'px';
        }
        textContent.addEventListener('input', autoGrow);
        TN.scrollbar(textContent, textContent.parentElement);

        // --- Coller (Ctrl+V) directement sur la page : fichier ou texte ------
        document.addEventListener('paste', (e) => {
            const clipboardData = e.clipboardData || window.clipboardData;
            if (!clipboardData) return;

            const files = [];
            for (const item of clipboardData.items) {
                if (item.kind === 'file') {
                    const file = item.getAsFile();
                    if (file) files.push(file);
                }
            }

            if (files.length > 0) {
                e.preventDefault();
                const dt = new DataTransfer();
                files.forEach(f => dt.items.add(f));
                trySubmitFiles(dt.files);
                return;
            }

            // Pas de fichier collé : si on est déjà en train d'éditer un champ,
            // on laisse le comportement natif (pas d'envoi automatique).
            const activeTag = document.activeElement ? document.activeElement.tagName : '';
            if (activeTag === 'TEXTAREA' || activeTag === 'INPUT') {
                return;
            }

            const text = clipboardData.getData('text/plain');
            if (text && text.trim() !== '') {
                e.preventDefault();
                textContent.value = text;
                document.getElementById('textForm').submit();
            }
        });

        // --- Copie des textes ------------------------------------------------
        document.querySelectorAll('.text-link').forEach(item => {
            const copy = () => {
                navigator.clipboard.writeText(item.dataset.content).then(() => {
                    const fileTimeElement = item.querySelector('.file-time');
                    if (fileTimeElement.classList.contains('is-copied')) return;
                    const originalHTML = fileTimeElement.innerHTML;
                    fileTimeElement.classList.add('is-copied');
                    fileTimeElement.textContent = 'Copié !';

                    setTimeout(() => {
                        fileTimeElement.classList.remove('is-copied');
                        fileTimeElement.innerHTML = originalHTML;
                        refreshTimes();
                    }, 2000);
                });
            };
            item.addEventListener('click', copy);
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    copy();
                }
            });
        });

        // --- Temps écoulé / restant, rafraîchis à la minute près -------------
        function formatTimeAgo(seconds) {
            if (seconds < 60) return "À l'instant";
            if (seconds < 3600) return `Il y a ${Math.floor(seconds / 60)} min`;
            if (seconds < 86400) {
                const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
                return `Il y a ${Math.floor(seconds / 3600)} h ${minutes}`;
            }
            return `Il y a ${Math.floor(seconds / 86400)} j`;
        }

        function formatRemaining(seconds) {
            if (seconds <= 60) return "moins d'1 min";
            if (seconds < 3600) return `${Math.ceil(seconds / 60)} min`;
            if (seconds < 3 * 3600) {
                let hours = Math.floor(seconds / 3600);
                let minutes = Math.round((seconds % 3600) / 60 / 5) * 5;
                if (minutes >= 60) {
                    hours++;
                    minutes = 0;
                }
                return `${hours} h` + (minutes > 0 ? ` ${String(minutes).padStart(2, '0')}` : '');
            }
            if (seconds < 86400) return `~${Math.round(seconds / 3600)} h`;
            let days = Math.floor(seconds / 86400);
            let hours = Math.round((seconds % 86400) / 3600);
            if (hours >= 24) {
                days++;
                hours = 0;
            }
            return `~${days} j` + (hours > 0 ? ` ${hours} h` : '');
        }

        function refreshTimes() {
            const now = (Date.now() + CLOCK_OFFSET) / 1000;
            document.querySelectorAll('[data-expires]').forEach(item => {
                const ago = item.querySelector('.js-ago');
                const remaining = item.querySelector('.js-remaining');
                if (!ago || !remaining) return; // message "Copié !" en cours
                const left = item.dataset.expires - now;
                if (left <= 0) {
                    item.remove();
                    return;
                }
                ago.textContent = formatTimeAgo(now - item.dataset.uploaded);
                remaining.textContent = formatRemaining(left);
            });
        }

        setInterval(refreshTimes, 30000);

        // --- Confirmation "Tout supprimer" ------------------------------------
        const clearAllForm = document.getElementById('clearAllForm');
        if (clearAllForm) {
            const modal = TN.modal(document.getElementById('clearAllModal'));
            clearAllForm.addEventListener('submit', (e) => {
                e.preventDefault();
                modal.open();
            });
            document.getElementById('confirmClearAll').addEventListener('click', () => {
                clearAllForm.submit();
            });
        }
    </script>
</body>

</html>
