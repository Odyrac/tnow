<?php

define('SHORT_RETENTION', 3600);
define('LONG_RETENTION', 12 * 3600);
const EXPIRY_META_FILE = '.expiry.json';

function loadExpiryMeta($directory = "uploads/")
{
    $metaFile = $directory . EXPIRY_META_FILE;
    if (!file_exists($metaFile)) {
        return array();
    }

    $data = json_decode(file_get_contents($metaFile), true);
    return is_array($data) ? $data : array();
}

function saveExpiryMeta($meta, $directory = "uploads/")
{
    file_put_contents($directory . EXPIRY_META_FILE, json_encode($meta), LOCK_EX);
}

// Enregistre la date d'expiration d'un fichier (utilisé pour la rétention longue de 12h)
function setFileExpiry($filename, $expiryTimestamp, $directory = "uploads/")
{
    $meta = loadExpiryMeta($directory);
    $meta[$filename] = $expiryTimestamp;
    saveExpiryMeta($meta, $directory);
}

function cleanOldFiles($directory = "uploads/", $maxAge = SHORT_RETENTION)
{
    if (!file_exists($directory)) {
        return false;
    }

    $meta = loadExpiryMeta($directory);
    $metaChanged = false;
    $files = glob($directory . "*");
    $now = time();
    $deletedCount = 0;

    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = basename($file);
            if ($basename === EXPIRY_META_FILE) {
                continue;
            }

            $expiry = isset($meta[$basename]) ? $meta[$basename] : (filemtime($file) + $maxAge);

            if ($now > $expiry) {
                if (unlink($file)) {
                    $deletedCount++;
                    if (isset($meta[$basename])) {
                        unset($meta[$basename]);
                        $metaChanged = true;
                    }
                }
            }
        }
    }

    if ($metaChanged) {
        saveExpiryMeta($meta, $directory);
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

// Convertit une valeur ini (ex: "20M", "1G") en nombre d'octets
function parseIniSize($size)
{
    $size = trim($size);
    $unit = strtoupper(substr($size, -1));
    $value = (float) $size;

    switch ($unit) {
        case 'G':
            $value *= 1024;
            // no break
        case 'M':
            $value *= 1024;
            // no break
        case 'K':
            $value *= 1024;
    }

    return (int) $value;
}

// Limites d'upload effectives du serveur (en octets), lues directement dans la config PHP
function getUploadLimits()
{
    return array(
        'file' => parseIniSize(ini_get('upload_max_filesize')),
        'post' => parseIniSize(ini_get('post_max_size')),
    );
}

// Message d'erreur lisible pour un code d'erreur $_FILES[...]['error']
function uploadErrorMessage($errorCode)
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "le fichier dépasse la taille maximale autorisée";
        case UPLOAD_ERR_PARTIAL:
            return "le fichier n'a été que partiellement envoyé";
        case UPLOAD_ERR_NO_FILE:
            return "aucun fichier n'a été envoyé";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return "erreur serveur lors de l'enregistrement du fichier";
        default:
            return "erreur inconnue lors de l'upload";
    }
}
