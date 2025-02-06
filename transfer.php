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

// Liste des fichiers avec leurs timestamps
$files = array();
$directory = "uploads/";

if (file_exists($directory)) {
    $items = array_diff(scandir($directory), array('..', '.'));
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
                <div class="drop-zone" id="dropZone">
                    <form action="upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                        <p>Glissez-déposez vos fichiers ici :</p>
                        <input type="file" name="files[]" id="fileInput" multiple class="form-control mb-3">
                        <button type="submit" class="btn btn-primary">Envoyer</button>
                    </form>
                </div>
            </div>

            <!-- Onglet Texte -->
            <div class="tab-pane fade" id="text" role="tabpanel">
                <form action="save_text.php" method="POST" id="textForm" class="text-center">
                    <div class="mb-3">
                        <textarea class="form-control" id="textContent" name="content" required placeholder="Collez votre texte ici..." autofocus></textarea>
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
                        $redirect = 'uploads/' . htmlspecialchars($file['name']);
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
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('fileInput');

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
            fileInput.files = e.dataTransfer.files;
            document.getElementById('uploadForm').submit();
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