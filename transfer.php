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

// Liste des fichiers avec leurs timestamps
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

foreach ($items as $item) {
    $files[] = array(
        'name' => $item,
        'time' => filemtime($directory . $item)
    );
}

// Trier les fichiers par date de modification (plus récent en premier)
usort($files, function ($a, $b) {
    return $b['time'] - $a['time'];
});

// Fonction pour formater la date
function formatTimeAgo($timestamp)
{
    $diff = time() - $timestamp;

    if ($diff < 1) {
        return 'Maintenant';
    } else if ($diff < 60) {
        return "Il y a " . $diff . " secondes";
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return "Il y a " . $minutes . " minute" . ($minutes > 1 ? 's' : '');
    } else {
        $minutes = floor(($diff % 3600) / 60);
        $hours = floor($diff / 3600);
        if ($hours < 1) {
            return "Il y a " . $minutes . " minute" . ($minutes > 1 ? 's' : '');
        }
        return "Il y a " . $hours . "h" . str_pad($minutes, 2, '0', STR_PAD_LEFT);
    }
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png" />
</head>

<body>
    <div class="login-container other-container">
        <div class="title-container">
            <img src="assets/logo.png" alt="Logo" class="logo">
            <h2 class="mb-0">Transfer Now</h2>
        </div>

        <ul class="nav nav-tabs mt-4" id="transferTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="files-tab" data-bs-toggle="tab" data-bs-target="#files" type="button" role="tab">Fichiers</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="text-tab" data-bs-toggle="tab" data-bs-target="#text" type="button" role="tab">Texte</button>
            </li>
        </ul>

        <!-- Contenu des onglets -->
        <div class="tab-content" id="transferTabsContent">
            <!-- Onglet Fichiers -->
            <div class="tab-pane fade show active" id="files" role="tabpanel">
                <?php if (!empty($uploadErrors)): ?>
                    <div class="alert alert-danger">
                        <?php foreach ($uploadErrors as $uploadError): ?>
                            <div><?php echo htmlspecialchars($uploadError); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div id="uploadClientError" class="alert alert-danger" style="display: none;"></div>
                <div class="drop-zone" id="dropZone">
                    <form action="upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <p>Glissez-déposez vos fichiers ici :</p>
                        <input type="file" name="files[]" id="fileInput" multiple class="form-control mb-3">
                        <div class="form-check mb-3 text-start">
                            <input type="checkbox" class="form-check-input" id="longDurationFiles" name="long_duration">
                            <label class="form-check-label" for="longDurationFiles">Conserver 12h (au lieu d'1h)</label>
                        </div>
                        <button type="submit" class="btn btn-primary">Envoyer</button>
                    </form>
                    <small class="text-muted d-block mt-2">
                        Taille max par fichier : <?php echo round($uploadLimits['file'] / 1024 / 1024, 1); ?> Mo
                        · Taille max au total : <?php echo round($uploadLimits['post'] / 1024 / 1024, 1); ?> Mo
                    </small>
                </div>
            </div>

            <!-- Onglet Texte -->
            <div class="tab-pane fade" id="text" role="tabpanel">
                <form action="save_text.php" method="POST" id="textForm" class="text-center">
                    <div class="mb-3">
                        <textarea class="form-control" id="textContent" name="content" required placeholder="Collez votre texte ici..." autofocus></textarea>
                    </div>
                    <div class="form-check mb-3 text-start">
                        <input type="checkbox" class="form-check-input" id="longDurationText" name="long_duration">
                        <label class="form-check-label" for="longDurationText">Conserver 12h (au lieu d'1h)</label>
                    </div>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </form>
            </div>
        </div>

        <?php if (count($files) > 0): ?>
            <h3 class="mb-3 mt-5">Éléments disponibles</h3>
            <div class="list-group">
                <?php foreach ($files as $file): ?>
                    <?php
                    $isTextFile = pathinfo($file['name'], PATHINFO_EXTENSION) === 'tnow';
                    $content = $isTextFile ? file_get_contents("uploads/" . $file['name']) : '';

                    if ($isTextFile) {
                        $redirect = "javascript:void(0);";
                    } else {
                        $redirect = 'download.php?file=' . urlencode($file['name']);
                    }
                    ?>
                    <a href="<?php echo $redirect; ?>"
                        class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                        target="<?php echo $isTextFile ? '' : '_blank'; ?>">
                        <div class="file-info <?php echo $isTextFile ? 'text-link' : ''; ?>" data-content="<?php echo htmlspecialchars($content); ?>">
                            <span class="filename"><?php echo $isTextFile ? htmlspecialchars($content) : htmlspecialchars($file['name']); ?></span>
                            <span class="file-time"><?php echo formatTimeAgo($file['time']); ?></span>
                        </div>
                        <?php if (!$isTextFile): ?>
                            <span class="badge bg-dark rounded-pill">
                                <?php echo round(filesize("uploads/" . $file['name']) / 1024 / 1024, 2); ?> MB
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Limites d'upload, synchronisées avec la config PHP du serveur (upload_max_filesize / post_max_size)
        const MAX_FILE_SIZE = <?php echo (int) $uploadLimits['file']; ?>;
        const MAX_TOTAL_SIZE = <?php echo (int) $uploadLimits['post']; ?>;
        // Marge de sécurité pour les en-têtes multipart et les autres champs du formulaire
        const UPLOAD_SAFETY_MARGIN = 64 * 1024;

        function formatMB(bytes) {
            return (bytes / 1024 / 1024).toFixed(1) + ' Mo';
        }

        const uploadForm = document.getElementById('uploadForm');
        const uploadClientError = document.getElementById('uploadClientError');

        function showUploadError(message) {
            uploadClientError.textContent = message;
            uploadClientError.style.display = 'block';
        }

        function clearUploadError() {
            uploadClientError.style.display = 'none';
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
                showUploadError(error);
                return;
            }
            clearUploadError();
            fileInput.files = fileList;
            uploadForm.submit();
        }

        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');

        fileInput.addEventListener('change', () => {
            const error = validateFiles(fileInput.files);
            if (error) {
                fileInput.value = '';
                showUploadError(error);
            } else {
                clearUploadError();
            }
        });

        uploadForm.addEventListener('submit', (e) => {
            const error = validateFiles(fileInput.files);
            if (error) {
                e.preventDefault();
                showUploadError(error);
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
    </script>

    <script>
        // Coller (Ctrl+V) directement sur la page : fichier ou texte
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
                document.getElementById('textContent').value = text;
                document.getElementById('textForm').submit();
            }
        });
    </script>

    <script>
        // Fonction pour gérer la copie du texte
        document.querySelectorAll('.text-link').forEach(button => {
            button.style.width = '100%';
            button.addEventListener('click', function() {
                const text = this.dataset.content;
                navigator.clipboard.writeText(text).then(() => {
                    const fileTimeElement = this.querySelector('.file-time');
                    const originalText = fileTimeElement.textContent;
                    fileTimeElement.classList.add('text-success');
                    fileTimeElement.textContent = 'Copié !';

                    setTimeout(() => {
                        fileTimeElement.classList.remove('text-success');
                        fileTimeElement.textContent = originalText;
                    }, 2000);
                });
            });
        });
    </script>
</body>

</html>