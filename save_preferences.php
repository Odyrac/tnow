<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    exit;
}

// Préférences d'affichage (durée de conservation, onglet ouvert) gardées jusqu'à la déconnexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['retention'])) {
        rememberRetentionKey($_POST['retention']);
    }
    if (isset($_POST['tab'])) {
        rememberTab($_POST['tab']);
    }
}

http_response_code(204);
