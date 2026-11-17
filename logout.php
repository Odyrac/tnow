<?php
session_start();
require_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !checkCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    http_response_code(400);
    die("Requête invalide");
}

// Vider la session et supprimer le cookie de session
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: index.php');
exit;
