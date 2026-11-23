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

// Espace occupé (éléments stockés + envois en cours) sur le quota total
$storageUsed = min(STORAGE_QUOTA, getStoredBytes($directory) + getPendingBytes($directory));

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
    if ($seconds <= 59 * 60) {
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
function renderRetentionSelect($id, $extraClass = '')
{
    $options = RETENTION_OPTIONS;
    $default = getSessionRetentionKey();
    ob_start(); ?>
    <div class="tn-select <?php echo $extraClass; ?>" data-sync="retention">
        <input type="hidden" name="retention" value="<?php echo $default; ?>">
        <button type="button" class="tn-select-trigger" id="<?php echo $id; ?>" aria-haspopup="listbox" aria-expanded="false">
            <svg class="tn-select-clock" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5" />
                <path d="M8 4.75V8l2.25 1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
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
<html lang="fr" class="tn-preload">

<head>
    <meta charset="UTF-8">
    <?php echo renderLoaderHead(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Now</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png" />
</head>

<body>
    <?php echo renderLoader(); ?>
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
                <form id="uploadForm">
                    <p class="drop-zone-title">Glissez-déposez vos fichiers ici</p>
                    <?php echo renderRetentionSelect('retentionFiles', 'tn-select--center'); ?>
                    <div class="tn-file" id="filePicker">
                        <input type="file" id="fileInput" multiple class="tn-visually-hidden">
                        <label for="fileInput" class="tn-btn tn-btn-primary tn-file-trigger">Sélectionner des fichiers</label>
                        <span class="tn-file-summary" id="fileSummary">Envoi automatique dès la sélection</span>
                        <span class="tn-file-progress" aria-hidden="true"><span id="fileProgress"></span></span>
                    </div>
                </form>
                <small class="drop-zone-hint">
                    <?php echo formatMegabytes($storageUsed); ?> utilisés sur <?php echo formatMegabytes(STORAGE_QUOTA); ?>
                    · <?php echo formatMegabytes(STORAGE_QUOTA - $storageUsed); ?> disponibles
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
                        <?php $url = getHttpUrl($content); ?>
                        <div class="tn-item" <?php echo $dataAttrs; ?>>
                            <div class="file-info">
                                <button type="button" class="filename tn-item-link text-link" title="Copier"
                                    data-content="<?php echo htmlspecialchars($content); ?>"><?php echo htmlspecialchars($content); ?></button>
                                <?php echo $meta; ?>
                            </div>
                            <div class="tn-item-actions">
                                <?php if ($url !== null): ?>
                                    <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener noreferrer" class="tn-btn tn-btn-view" draggable="false">Ouvrir</a>
                                <?php endif; ?>
                                <span class="tn-badge"><?php echo $url !== null ? 'Lien' : 'Texte'; ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php
                        $fileUrl = 'download.php?file=' . urlencode($file['name']);
                        $viewable = isViewableMimeType(getFileMimeType($directory . $file['name']));
                        ?>
                        <div class="tn-item" <?php echo $dataAttrs; ?>>
                            <div class="file-info">
                                <a href="<?php echo $fileUrl; ?>" download class="filename tn-item-link" draggable="false"
                                    title="Télécharger"><?php echo htmlspecialchars($file['name']); ?></a>
                                <?php echo $meta; ?>
                            </div>
                            <div class="tn-item-actions">
                                <?php if ($viewable): ?>
                                    <a href="<?php echo $fileUrl; ?>&amp;view=1" target="_blank" rel="noopener" class="tn-btn tn-btn-view" draggable="false">Voir</a>
                                <?php endif; ?>
                                <span class="tn-badge"><?php echo formatSize(filesize($directory . $file['name'])); ?></span>
                            </div>
                        </div>
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
        // Quota de stockage total et taille des morceaux (sous les limites du serveur)
        const STORAGE_QUOTA = <?php echo (int) STORAGE_QUOTA; ?>;
        const STORAGE_USED = <?php echo (int) $storageUsed; ?>;
        const CHUNK_SIZE = <?php echo (int) getChunkSize(); ?>;
        const CSRF_TOKEN = <?php echo json_encode(getCsrfToken()); ?>;
        const UPLOAD_ERRORS_KEY = 'tn-upload-errors';
        // Décalage entre l'horloge du serveur et celle du navigateur
        const CLOCK_OFFSET = <?php echo $now; ?> * 1000 - Date.now();

        function formatMB(bytes) {
            return (bytes / 1024 / 1024).toFixed(1) + ' Mo';
        }

        // --- Onglets ---------------------------------------------------------
        TN.tabs(document.querySelector('.tn-tabs'));

        // --- Selects de durée (synchronisés entre les deux formulaires) ------
        document.querySelectorAll('.tn-select').forEach(el => TN.select(el));

        // Mémorise la durée choisie dans la session (conservée jusqu'à la déconnexion)
        document.querySelectorAll('.tn-select[data-sync="retention"]').forEach(el => {
            el.addEventListener('tn:change', (e) => {
                const body = new FormData();
                body.append('retention', e.detail.value);
                fetch('save_retention.php', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(() => {});
            });
        });

        // --- Envoi de fichiers -----------------------------------------------
        const uploadForm = document.getElementById('uploadForm');
        const uploadClientError = document.getElementById('uploadClientError');
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');
        const filePicker = document.getElementById('filePicker');
        const fileSummary = document.getElementById('fileSummary');
        const fileProgress = document.getElementById('fileProgress');
        const defaultSummary = fileSummary.textContent;
        let uploading = false;

        function showUploadError(messages) {
            uploadClientError.replaceChildren(...[].concat(messages).map(message => {
                const line = document.createElement('div');
                line.textContent = message;
                return line;
            }));
            uploadClientError.hidden = false;
        }

        function clearUploadError() {
            uploadClientError.hidden = true;
            uploadClientError.replaceChildren();
        }

        // Erreurs de l'envoi précédent, conservées pendant le rechargement de la page
        const pendingErrors = sessionStorage.getItem(UPLOAD_ERRORS_KEY);
        if (pendingErrors) {
            sessionStorage.removeItem(UPLOAD_ERRORS_KEY);
            try {
                showUploadError(JSON.parse(pendingErrors));
            } catch (e) {}
        }

        // Vérifie que la sélection tient dans l'espace disponible avant tout envoi
        function validateFiles(files) {
            if (files.length === 0) {
                return "Sélectionnez au moins un fichier avant d'envoyer.";
            }

            const tooLarge = files.find(f => f.size > STORAGE_QUOTA);
            if (tooLarge) {
                return `"${tooLarge.name}" (${formatMB(tooLarge.size)}) dépasse la limite de ${formatMB(STORAGE_QUOTA)}.`;
            }

            const totalSize = files.reduce((sum, f) => sum + f.size, 0);
            const available = Math.max(0, STORAGE_QUOTA - STORAGE_USED);
            if (totalSize > available) {
                return `Espace insuffisant : ${formatMB(totalSize)} sélectionnés pour ${formatMB(available)} disponibles (limite de ${formatMB(STORAGE_QUOTA)} au total). Supprimez des éléments ou envoyez moins de fichiers.`;
            }

            return null;
        }

        function randomUploadId() {
            const bytes = crypto.getRandomValues(new Uint8Array(16));
            return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        }

        // Envoie un morceau ; XHR plutôt que fetch pour suivre la progression
        function postChunk(fields, blob, onProgress) {
            return new Promise((resolve, reject) => {
                const body = new FormData();
                Object.keys(fields).forEach(key => body.append(key, fields[key]));
                body.append('chunk', blob, 'chunk');

                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'upload_chunk.php');
                xhr.upload.addEventListener('progress', e => onProgress(Math.min(e.loaded, blob.size)));
                xhr.addEventListener('load', () => {
                    let data = {};
                    try {
                        data = JSON.parse(xhr.responseText) || {};
                    } catch (e) {}
                    resolve({ status: xhr.status, data: data });
                });
                xhr.addEventListener('error', () => reject(new Error('connexion interrompue')));
                xhr.send(body);
            });
        }

        const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

        // Envoie un fichier morceau par morceau, avec reprise en cas de coupure
        async function uploadFile(file, retention, onProgress) {
            const id = randomUploadId();
            let offset = 0;
            let attempts = 0;

            for (;;) {
                const blob = file.slice(offset, offset + CHUNK_SIZE);
                const fields = {
                    csrf_token: CSRF_TOKEN,
                    upload_id: id,
                    name: file.name,
                    size: file.size,
                    offset: offset,
                    retention: retention,
                };
                const start = offset;
                let res = null;
                try {
                    res = await postChunk(fields, blob, loaded => onProgress(start + loaded));
                } catch (e) {}

                if (res && res.status === 200) {
                    if (res.data.done) return;
                    offset = res.data.received;
                    attempts = 0;
                    continue;
                }

                // Le serveur indique où reprendre (morceau perdu ou déjà reçu)
                if (res && res.status === 409 && typeof res.data.received === 'number') {
                    offset = res.data.received;
                } else if (res && res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) {
                    // Refus définitif (quota, session expirée...) : inutile de réessayer
                    throw new Error(res.data.error || (res.status === 413 ? 'refusé par le serveur (morceau trop volumineux)' : `erreur ${res.status}`));
                }

                // Coupure réseau ou erreur serveur passagère : quelques nouveaux essais
                if (++attempts > 4) {
                    throw new Error(res && res.data.error ? res.data.error : 'connexion interrompue');
                }
                await sleep(1000 * attempts);
            }
        }

        function setProgress(ratio) {
            fileProgress.style.width = (Math.max(0, Math.min(1, ratio)) * 100).toFixed(1) + '%';
        }

        // Valide puis envoie une sélection de fichiers, l'un après l'autre
        async function trySubmitFiles(fileList) {
            if (uploading) {
                showUploadError('Un envoi est déjà en cours, patientez jusqu\'à la fin.');
                return;
            }

            const files = Array.from(fileList);
            const error = validateFiles(files);
            if (error) {
                fileInput.value = '';
                fileSummary.textContent = defaultSummary;
                filePicker.classList.remove('has-files');
                showUploadError(error);
                return;
            }

            clearUploadError();
            uploading = true;
            fileInput.disabled = true;
            filePicker.classList.add('has-files', 'is-uploading');

            const retention = uploadForm.querySelector('input[name="retention"]').value;
            const totalSize = files.reduce((sum, f) => sum + f.size, 0);
            const errors = [];
            let doneSize = 0;

            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const prefix = files.length > 1 ? `${i + 1}/${files.length} · ` : '';
                const update = loaded => {
                    const ratio = totalSize > 0 ? (doneSize + loaded) / totalSize : 1;
                    setProgress(ratio);
                    fileSummary.textContent = `${Math.floor(ratio * 100)} % · ${prefix}${file.name}`;
                };
                update(0);
                try {
                    await uploadFile(file, retention, update);
                } catch (e) {
                    errors.push(`${file.name} : ${e.message}`);
                }
                doneSize += file.size;
            }

            setProgress(1);
            uploading = false;
            if (errors.length > 0) {
                sessionStorage.setItem(UPLOAD_ERRORS_KEY, JSON.stringify(errors));
            }
            window.location.replace('transfer.php');
        }

        uploadForm.addEventListener('submit', e => e.preventDefault());

        // Avertit avant de quitter la page pendant un envoi
        window.addEventListener('beforeunload', (e) => {
            if (uploading) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

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
        document.querySelectorAll('.text-link').forEach(button => {
            button.addEventListener('click', () => {
                navigator.clipboard.writeText(button.dataset.content).then(() => {
                    const fileTimeElement = button.closest('.tn-item').querySelector('.file-time');
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
            if (seconds <= 59 * 60) return `${Math.ceil(seconds / 60)} min`;
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
