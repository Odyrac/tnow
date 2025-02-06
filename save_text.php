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

if (isset($content) && !empty($content)) {
    $filename = date('Y-m-d_His') . '.tnow';
    $filepath = 'uploads/' . $filename;

    file_put_contents($filepath, $content);
}

header('Location: transfer.php');
exit;
