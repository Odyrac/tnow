<?php
// Réception d'un fichier découpé en morceaux (voir l'envoi dans transfer.php).
// Chaque requête reste sous les limites du serveur ; le fichier est reconstitué
// au fil des morceaux puis déplacé dans uploads/ une fois complet.
session_start();
require_once 'functions.php';

header('Content-Type: application/json; charset=utf-8');

function respond($status, $data)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function fail($status, $message, $extra = array())
{
    respond($status, array_merge(array('error' => $message), $extra));
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    fail(401, "session expirée, reconnectez-vous");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, "méthode non autorisée");
}

// post_max_size dépassé : PHP vide $_POST et $_FILES
if (empty($_POST)) {
    fail(413, "un morceau dépasse la limite du serveur");
}

if (!checkCsrfToken(isset($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    fail(403, "requête invalide, rechargez la page");
}

if (shouldCleanFiles()) {
    cleanOldFiles();
}

$retentionKey = isset($_POST['retention']) ? $_POST['retention'] : null;
rememberRetentionKey($retentionKey);

// Libère la session : les autres requêtes (pages, morceaux) ne sont pas bloquées pendant l'écriture
session_write_close();

$uploadDir = "uploads/";
$chunksRoot = getChunksRoot($uploadDir);
if (!is_dir($chunksRoot)) {
    mkdir($chunksRoot, 0777, true);
}

$id = isset($_POST['upload_id']) ? (string) $_POST['upload_id'] : '';
$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : -1;
$size = isset($_POST['size']) ? (int) $_POST['size'] : -1;
$name = isset($_POST['name']) ? (string) $_POST['name'] : '';

if (!preg_match('/^[a-f0-9]{32}$/', $id) || $offset < 0 || $size < 0 || $offset > $size) {
    fail(400, "requête d'envoi invalide");
}

if ($size > STORAGE_QUOTA) {
    fail(413, "le fichier (" . formatMegabytes($size) . ") dépasse la limite de " . formatMegabytes(STORAGE_QUOTA));
}

// Morceau reçu (un fichier vide arrive sans contenu)
$chunk = isset($_FILES['chunk']) ? $_FILES['chunk'] : null;
$chunkError = $chunk ? $chunk['error'] : UPLOAD_ERR_NO_FILE;
if ($chunkError === UPLOAD_ERR_NO_FILE && $size === 0) {
    $chunkLength = 0;
} elseif ($chunkError !== UPLOAD_ERR_OK) {
    fail($chunkError === UPLOAD_ERR_INI_SIZE ? 413 : 500, uploadErrorMessage($chunkError));
} else {
    $chunkLength = (int) $chunk['size'];
}

if ($chunkLength > getChunkSize()) {
    fail(413, "un morceau dépasse la taille autorisée");
}

$chunkDir = $chunksRoot . $id . '/';
$partFile = $chunkDir . 'data.part';

$lock = acquireStorageLock($uploadDir);

// Premier morceau : on réserve la place du fichier entier dans le quota
if (!is_dir($chunkDir)) {
    if ($offset !== 0) {
        releaseStorageLock($lock);
        fail(409, "envoi introuvable ou expiré, recommencez");
    }

    cleanStaleChunks($uploadDir);
    $available = STORAGE_QUOTA - getStoredBytes($uploadDir) - getPendingBytes($uploadDir);
    if ($size > $available) {
        releaseStorageLock($lock);
        fail(413, "espace insuffisant (" . formatMegabytes($size) . " pour " . formatMegabytes(max(0, $available)) . " disponibles sur " . formatMegabytes(STORAGE_QUOTA) . ")");
    }

    mkdir($chunkDir, 0777, true);
    touch($partFile);
    file_put_contents($chunkDir . 'meta.json', json_encode(array(
        'name' => sanitizeUploadName($name),
        'size' => $size,
        'retention' => getRetentionSeconds($retentionKey),
    )), LOCK_EX);
}

$meta = loadChunkMeta($chunkDir);
if ($meta === null || (int) $meta['size'] !== $size) {
    releaseStorageLock($lock);
    fail(400, "requête d'envoi incohérente");
}

clearstatcache(true, $partFile);
$received = is_file($partFile) ? filesize($partFile) : 0;

// Morceau déjà reçu (nouvel essai après une coupure) : on indique où reprendre
if ($offset < $received) {
    releaseStorageLock($lock);
    respond(200, array('received' => $received));
}

if ($offset !== $received) {
    releaseStorageLock($lock);
    fail(409, "morceau inattendu", array('received' => $received));
}

if ($received + $chunkLength > $size) {
    releaseStorageLock($lock);
    fail(400, "taille de fichier incohérente");
}

// Ajout du morceau à la suite du fichier partiel, en flux (pas de chargement en mémoire)
if ($chunkLength > 0) {
    $in = fopen($chunk['tmp_name'], 'rb');
    $out = fopen($partFile, 'ab');
    $written = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
    if ($in) {
        fclose($in);
    }
    if ($out) {
        fclose($out);
    }
    clearstatcache(true, $partFile);
    if ($written !== $chunkLength) {
        // Retour à l'état précédent pour permettre un nouvel essai propre
        $handle = fopen($partFile, 'r+b');
        if ($handle) {
            ftruncate($handle, $received);
            fclose($handle);
        }
        releaseStorageLock($lock);
        fail(500, "erreur d'écriture sur le serveur");
    }
    $received += $chunkLength;
}

// Dernier morceau : le fichier rejoint la liste
if ($received === $size) {
    $finalName = uniqueUploadName($meta['name'], $uploadDir);
    if (!rename($partFile, $uploadDir . $finalName)) {
        releaseStorageLock($lock);
        fail(500, "erreur lors de l'enregistrement sur le serveur");
    }
    $retention = (int) $meta['retention'];
    setFileExpiry($finalName, time() + $retention, $retention, $uploadDir);
    removeChunkUpload($chunkDir);
    releaseStorageLock($lock);
    respond(200, array('done' => true, 'received' => $received));
}

releaseStorageLock($lock);
respond(200, array('received' => $received));
