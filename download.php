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

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filepath) ?: 'application/octet-stream';
finfo_close($finfo);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filepath));
header('Content-Disposition: inline; filename="' . $filename . '"');
header('X-Content-Type-Options: nosniff');

readfile($filepath);
exit;
