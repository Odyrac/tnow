<?php

function cleanOldFiles($directory = "uploads/", $maxAge = 3600)
{
    if (!file_exists($directory)) {
        return false;
    }

    $files = glob($directory . "*");
    $now = time();
    $deletedCount = 0;

    foreach ($files as $file) {
        if (is_file($file)) {
            if ($now - filemtime($file) > $maxAge) {
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
        }
    }

    return $deletedCount;
}

// Fonction pour s'assurer que le nettoyage n'est pas exécuté trop fréquemment
function shouldCleanFiles()
{
    $lastClean = isset($_SESSION['last_cleanup']) ? $_SESSION['last_cleanup'] : 0;
    $now = time();

    // Nettoyer au maximum toutes les 5 minutes
    if ($now - $lastClean > 300) {
        $_SESSION['last_cleanup'] = $now;
        return true;
    }

    return false;
}
