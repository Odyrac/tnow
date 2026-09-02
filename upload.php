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

$errors = array();
$retention = isset($_POST['long_duration']) ? LONG_RETENTION : SHORT_RETENTION;
$limits = getUploadLimits();

// Quand le volume total dépasse post_max_size, PHP vide $_POST et $_FILES
// sans lever d'erreur exploitable : on le détecte via CONTENT_LENGTH.
if (empty($_POST) && empty($_FILES)) {
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > 0) {
        $errors[] = "Le volume total envoyé (" . round($contentLength / 1024 / 1024, 1) . " Mo) dépasse la limite du serveur (" . round($limits['post'] / 1024 / 1024, 1) . " Mo). Réduisez le nombre ou la taille des fichiers et réessayez.";
    }
}

if (isset($_FILES['files'])) {
    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
        $file_name = $_FILES['files']['name'][$key];
        $file_error = $_FILES['files']['error'][$key];

        // Sécuriser le nom du fichier
        $file_name = preg_replace("/[^a-zA-Z0-9.]/", "_", $file_name);

        if ($file_error === UPLOAD_ERR_NO_FILE) {
            // Champ de fichier laissé vide : rien à signaler
            continue;
        }

        if ($file_error !== UPLOAD_ERR_OK) {
            $errors[] = $file_name . " : " . uploadErrorMessage($file_error);
            continue;
        }

        $file_tmp = $_FILES['files']['tmp_name'][$key];

        // Vérifier si le fichier existe déjà et ajouter un timestamp si nécessaire
        if (file_exists($uploadDir . $file_name)) {
            $file_name = time() . '_' . $file_name;
        }

        if (move_uploaded_file($file_tmp, $uploadDir . $file_name)) {
            setFileExpiry($file_name, time() + $retention, $uploadDir);
        } else {
            $errors[] = $file_name . " : erreur lors de l'enregistrement sur le serveur";
        }
    }
}

if (!empty($errors)) {
    $_SESSION['upload_errors'] = $errors;
}

header('Location: transfer.php');
exit;
