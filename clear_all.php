<?php
session_start();
require_once 'functions.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !checkCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(400);
    die("Requête invalide");
}

clearAllFiles("uploads/");

header('Location: transfer.php');
exit;
