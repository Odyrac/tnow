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
$items = array_diff(scandir($directory), array('..', '.'));

foreach ($items as $item) {
    $files[] = array(
        'name' => $item,
        'time' => filemtime($directory . $item)
    );
}

// Trier les fichiers par date de modification (plus récent en premier)
usort($files, function($a, $b) {
    return $b['time'] - $a['time'];
});

// Fonction pour formater la date
function formatTimeAgo($timestamp) {
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
        
        <div class="drop-zone" id="dropZone">
            <form action="upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                <p>Glissez-déposez vos fichiers ici :</p>
                <input type="file" name="files[]" id="fileInput" multiple class="form-control mb-3">
                <button type="submit" class="btn btn-primary">Envoyer</button>
            </form>
        </div>

        <h3 class="mb-3 mt-5">Fichiers disponibles</h3>
        <div class="list-group">
            <?php foreach($files as $file): ?>
                <a href="uploads/<?php echo htmlspecialchars($file['name']); ?>" 
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" 
                   target="_blank">
                    <div class="file-info">
                        <span class="filename"><?php echo htmlspecialchars($file['name']); ?></span>
                        <span class="file-time"><?php echo formatTimeAgo($file['time']); ?></span>
                    </div>
                    <span class="badge bg-dark rounded-pill">
                        <?php echo round(filesize("uploads/" . $file['name']) / 1024 / 1024, 2); ?> MB
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

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
</body>
</html>