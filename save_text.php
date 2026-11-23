<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    die("Accès non autorisé");
}

$uploadDir = "uploads/";

// Nettoyage périodique des fichiers
if (shouldCleanFiles()) {
    cleanOldFiles();
}

// Créer le dossier uploads s'il n'existe pas
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$content = trim($_POST['content']);
$retentionKey = isset($_POST['retention']) ? $_POST['retention'] : null;
rememberRetentionKey($retentionKey);
$retention = getRetentionSeconds($retentionKey);

if (isset($content) && !empty($content)) {
    $lock = acquireStorageLock($uploadDir);
    $available = STORAGE_QUOTA - getStoredBytes($uploadDir) - getPendingBytes($uploadDir);

    if (strlen($content) > $available) {
        $_SESSION['upload_errors'] = array("Espace insuffisant pour enregistrer ce texte (" . formatMegabytes(max(0, $available)) . " disponibles sur " . formatMegabytes(STORAGE_QUOTA) . ").");
    } else {
        $filename = uniqueUploadName(date('Y-m-d_His') . '.tnow', $uploadDir);
        file_put_contents($uploadDir . $filename, $content);
        setFileExpiry($filename, time() + $retention, $retention, $uploadDir);
    }

    releaseStorageLock($lock);
}

header('Location: transfer.php');
exit;
