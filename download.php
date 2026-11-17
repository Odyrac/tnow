<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: index.php");
    exit;
}

$uploadDir = "uploads/";

if (!isset($_GET['file'])) {
    http_response_code(400);
    die("Fichier non spécifié");
}

// basename() empêche toute traversée de répertoire (../)
$filename = basename($_GET['file']);
$filepath = $uploadDir . $filename;

if (!is_file($filepath)) {
    http_response_code(404);
    die("Fichier introuvable");
}

$mimeType = getFileMimeType($filepath);

// Par défaut : téléchargement. Affichage dans le navigateur uniquement sur demande (?view=1)
// et seulement pour les types sûrs.
$inline = isset($_GET['view']) && isViewableMimeType($mimeType);

if (!$inline) {
    $mimeType = 'application/octet-stream';
} elseif ($mimeType === 'text/plain') {
    $mimeType = 'text/plain; charset=utf-8';
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filepath));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Content-Type-Options: nosniff');

readfile($filepath);
exit;
