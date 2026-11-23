<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(403);
    exit;
}

// Mémorise la durée de conservation choisie jusqu'à la déconnexion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rememberRetentionKey(isset($_POST['retention']) ? $_POST['retention'] : null);
}

http_response_code(204);
