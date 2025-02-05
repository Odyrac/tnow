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

$response = array();

if (isset($_FILES['files'])) {
    foreach ($_FILES['files']['tmp_name'] as $key => $tmp_name) {
        $file_name = $_FILES['files']['name'][$key];
        $file_size = $_FILES['files']['size'][$key];
        $file_tmp = $_FILES['files']['tmp_name'][$key];
        $file_type = $_FILES['files']['type'][$key];
        
        // Sécuriser le nom du fichier
        $file_name = preg_replace("/[^a-zA-Z0-9.]/", "_", $file_name);
        
        // Vérifier si le fichier existe déjà et ajouter un timestamp si nécessaire
        if (file_exists($uploadDir . $file_name)) {
            $file_name = time() . '_' . $file_name;
        }
        
        if (move_uploaded_file($file_tmp, $uploadDir . $file_name)) {
            $response[] = array(
                'status' => 'success',
                'message' => 'Fichier uploadé avec succès',
                'filename' => $file_name
            );
        } else {
            $response[] = array(
                'status' => 'error',
                'message' => 'Erreur lors de l\'upload',
                'filename' => $file_name
            );
        }
    }
}

header('Location: transfer.php');
exit;
?>