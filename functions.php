<?php

define('SHORT_RETENTION', 3600);
const EXPIRY_META_FILE = '.expiry.json';

// Durées de conservation proposées (clé envoyée par le formulaire => libellé + durée en secondes)
const RETENTION_OPTIONS = array(
    '1h' => array('label' => '1 heure', 'seconds' => 3600),
    '12h' => array('label' => '12 heures', 'seconds' => 12 * 3600),
    '1d' => array('label' => '1 jour', 'seconds' => 24 * 3600),
    '3d' => array('label' => '3 jours', 'seconds' => 3 * 24 * 3600),
);
const DEFAULT_RETENTION = '1h';

// Espace maximal occupé par l'ensemble des éléments stockés (fichiers + textes)
const STORAGE_QUOTA = 500 * 1024 * 1024;
// Taille maximale d'un morceau d'envoi : les gros fichiers sont découpés pour rester
// sous les limites du serveur (upload_max_filesize, post_max_size, serveur web)
const CHUNK_MAX_SIZE = 8 * 1024 * 1024;
// Dossier caché (dans uploads/) des envois en cours
const CHUNKS_DIR = '.chunks';
// Un envoi sans activité depuis ce délai est considéré comme abandonné et effacé
const STALE_CHUNK_AGE = 3600;
const STORAGE_LOCK_FILE = '.storage.lock';

// Durée de conservation (en secondes) à partir de la clé reçue ; valeur par défaut si inconnue
function getRetentionSeconds($key)
{
    $options = RETENTION_OPTIONS;
    if (!is_string($key) || !isset($options[$key])) {
        $key = DEFAULT_RETENTION;
    }
    return $options[$key]['seconds'];
}

// Durée de conservation choisie pendant la session (réinitialisée à la déconnexion)
function getSessionRetentionKey()
{
    $options = RETENTION_OPTIONS;
    $key = isset($_SESSION['retention']) ? $_SESSION['retention'] : null;
    return is_string($key) && isset($options[$key]) ? $key : DEFAULT_RETENTION;
}

// Mémorise la durée choisie dans la session si elle est valide
function rememberRetentionKey($key)
{
    $options = RETENTION_OPTIONS;
    if (is_string($key) && isset($options[$key])) {
        $_SESSION['retention'] = $key;
    }
}

// Libellé lisible d'une durée de conservation (ex : 43200 => "12 heures")
function retentionLabel($seconds)
{
    foreach (RETENTION_OPTIONS as $option) {
        if ($option['seconds'] === (int) $seconds) {
            return $option['label'];
        }
    }
    $hours = max(1, (int) round($seconds / 3600));
    return $hours % 24 === 0
        ? ($hours / 24) . ' jour' . ($hours / 24 > 1 ? 's' : '')
        : $hours . ' heure' . ($hours > 1 ? 's' : '');
}

function loadExpiryMeta($directory = "uploads/")
{
    $metaFile = $directory . EXPIRY_META_FILE;
    if (!file_exists($metaFile)) {
        return array();
    }

    $data = json_decode(file_get_contents($metaFile), true);
    return is_array($data) ? $data : array();
}

function saveExpiryMeta($meta, $directory = "uploads/")
{
    file_put_contents($directory . EXPIRY_META_FILE, json_encode($meta), LOCK_EX);
}

// Enregistre la date d'expiration et la durée de conservation choisie pour un fichier
function setFileExpiry($filename, $expiryTimestamp, $retentionSeconds, $directory = "uploads/")
{
    $meta = loadExpiryMeta($directory);
    $meta[$filename] = array('expires' => $expiryTimestamp, 'retention' => $retentionSeconds);
    saveExpiryMeta($meta, $directory);
}

// Expiration et durée de conservation d'un fichier.
// Gère l'ancien format (timestamp seul) et les fichiers sans métadonnées (rétention courte).
function getFileExpiry($meta, $basename, $filepath, $defaultRetention = SHORT_RETENTION)
{
    $entry = isset($meta[$basename]) ? $meta[$basename] : null;

    if (is_array($entry) && isset($entry['expires'])) {
        $expires = (int) $entry['expires'];
        $retention = isset($entry['retention']) ? (int) $entry['retention'] : $expires - filemtime($filepath);
    } elseif (is_numeric($entry)) {
        $expires = (int) $entry;
        $retention = $expires - filemtime($filepath);
    } else {
        $expires = filemtime($filepath) + $defaultRetention;
        $retention = $defaultRetention;
    }

    return array('expires' => $expires, 'retention' => $retention);
}

function cleanOldFiles($directory = "uploads/", $maxAge = SHORT_RETENTION)
{
    if (!file_exists($directory)) {
        return false;
    }

    $meta = loadExpiryMeta($directory);
    $metaChanged = false;
    $files = glob($directory . "*");
    $now = time();
    $deletedCount = 0;

    foreach ($files as $file) {
        if (is_file($file)) {
            $basename = basename($file);
            if ($basename === EXPIRY_META_FILE) {
                continue;
            }

            $expiry = getFileExpiry($meta, $basename, $file, $maxAge);

            if ($now > $expiry['expires']) {
                if (unlink($file)) {
                    $deletedCount++;
                    if (isset($meta[$basename])) {
                        unset($meta[$basename]);
                        $metaChanged = true;
                    }
                }
            }
        }
    }

    if ($metaChanged) {
        saveExpiryMeta($meta, $directory);
    }

    cleanStaleChunks($directory);

    return $deletedCount;
}

// --- Envoi par morceaux et quota de stockage ----------------------------------

// Taille des morceaux : sous les limites PHP (avec une marge pour l'enveloppe multipart)
function getChunkSize()
{
    $limits = getUploadLimits();
    $size = CHUNK_MAX_SIZE;
    if ($limits['file'] > 0) {
        $size = min($size, $limits['file']);
    }
    if ($limits['post'] > 0) {
        $size = min($size, $limits['post'] - 64 * 1024);
    }
    return max(256 * 1024, $size);
}

// Nom de fichier sûr : caractères simples, pas de fichier caché, longueur limitée
function sanitizeUploadName($name)
{
    $name = preg_replace("/[^a-zA-Z0-9.]/", "_", (string) $name);
    $name = ltrim($name, '.');
    if (strlen($name) > 200) {
        $name = substr($name, -200);
    }
    return $name !== '' ? $name : 'fichier';
}

// Nom libre dans le dossier d'upload (préfixe horodaté en cas de doublon)
function uniqueUploadName($name, $directory = "uploads/")
{
    if (!file_exists($directory . $name)) {
        return $name;
    }
    $prefix = time();
    $candidate = $prefix . '_' . $name;
    $i = 1;
    while (file_exists($directory . $candidate)) {
        $candidate = $prefix . '_' . $i++ . '_' . $name;
    }
    return $candidate;
}

// Volume occupé par les éléments terminés (fichiers visibles du dossier)
function getStoredBytes($directory = "uploads/")
{
    $total = 0;
    if (!is_dir($directory)) {
        return 0;
    }
    foreach (scandir($directory) as $item) {
        if ($item[0] !== '.' && is_file($directory . $item)) {
            $total += filesize($directory . $item);
        }
    }
    return $total;
}

function getChunksRoot($directory = "uploads/")
{
    return $directory . CHUNKS_DIR . '/';
}

// Liste des envois en cours : identifiant => chemin du dossier
function listChunkUploads($directory = "uploads/")
{
    $root = getChunksRoot($directory);
    $uploads = array();
    if (!is_dir($root)) {
        return $uploads;
    }
    foreach (scandir($root) as $item) {
        if ($item[0] !== '.' && is_dir($root . $item)) {
            $uploads[$item] = $root . $item . '/';
        }
    }
    return $uploads;
}

function loadChunkMeta($chunkDir)
{
    $file = $chunkDir . 'meta.json';
    if (!is_file($file)) {
        return null;
    }
    $meta = json_decode(file_get_contents($file), true);
    return is_array($meta) ? $meta : null;
}

// Place réservée par les envois en cours (taille annoncée des fichiers)
function getPendingBytes($directory = "uploads/")
{
    $total = 0;
    foreach (listChunkUploads($directory) as $chunkDir) {
        $meta = loadChunkMeta($chunkDir);
        if ($meta !== null) {
            $total += (int) $meta['size'];
        }
    }
    return $total;
}

// Supprime un envoi en cours (morceaux + métadonnées)
function removeChunkUpload($chunkDir, $secure = false)
{
    if (!is_dir($chunkDir)) {
        return;
    }
    foreach (scandir($chunkDir) as $item) {
        if ($item !== '.' && $item !== '..' && is_file($chunkDir . $item)) {
            $secure ? secureDelete($chunkDir . $item) : @unlink($chunkDir . $item);
        }
    }
    @rmdir($chunkDir);
}

// Efface les envois abandonnés (onglet fermé, connexion perdue...)
function cleanStaleChunks($directory = "uploads/")
{
    $now = time();
    foreach (listChunkUploads($directory) as $chunkDir) {
        $lastActivity = filemtime($chunkDir);
        foreach (array('data.part', 'meta.json') as $file) {
            if (is_file($chunkDir . $file)) {
                $lastActivity = max($lastActivity, filemtime($chunkDir . $file));
            }
        }
        if ($now - $lastActivity > STALE_CHUNK_AGE) {
            removeChunkUpload($chunkDir, true);
        }
    }
}

// Verrou exclusif sur le stockage (réservation de place, finalisation des envois)
function acquireStorageLock($directory = "uploads/")
{
    $handle = fopen($directory . STORAGE_LOCK_FILE, 'c');
    if ($handle !== false) {
        flock($handle, LOCK_EX);
    }
    return $handle;
}

function releaseStorageLock($handle)
{
    if ($handle) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function formatMegabytes($bytes)
{
    return round($bytes / 1024 / 1024, 1) . ' Mo';
}

// Suppression "sans trace" d'un fichier : écrase le contenu avec des octets aléatoires,
// force l'écriture sur disque, tronque, renomme avec un nom aléatoire puis supprime.
function secureDelete($filepath)
{
    if (!is_file($filepath)) {
        return false;
    }

    $size = filesize($filepath);
    $handle = @fopen($filepath, 'r+b');

    if ($handle !== false) {
        $chunkSize = 1024 * 1024;
        $remaining = $size;
        while ($remaining > 0) {
            $length = min($chunkSize, $remaining);
            fwrite($handle, random_bytes($length));
            $remaining -= $length;
        }
        fflush($handle);
        if (function_exists('fsync')) {
            fsync($handle);
        }
        ftruncate($handle, 0);
        fclose($handle);
    }

    // Renommer avant suppression pour ne pas laisser le nom d'origine dans l'entrée du répertoire
    $randomPath = dirname($filepath) . '/' . bin2hex(random_bytes(16));
    if (@rename($filepath, $randomPath)) {
        $filepath = $randomPath;
    }

    clearstatcache(true, $filepath);
    return @unlink($filepath);
}

// Supprime définitivement tous les éléments du dossier d'upload et réinitialise les métadonnées
function clearAllFiles($directory = "uploads/")
{
    if (!file_exists($directory)) {
        return 0;
    }

    $deletedCount = 0;
    foreach (scandir($directory) as $item) {
        // On conserve les fichiers cachés système (.htaccess) ; les métadonnées sont traitées après
        if ($item === '.' || $item === '..' || $item[0] === '.') {
            continue;
        }
        if (is_file($directory . $item) && secureDelete($directory . $item)) {
            $deletedCount++;
        }
    }

    // Effacer aussi les métadonnées d'expiration (qui contiennent les noms des fichiers)
    $metaFile = $directory . EXPIRY_META_FILE;
    if (is_file($metaFile)) {
        secureDelete($metaFile);
    }

    // Et les envois en cours
    foreach (listChunkUploads($directory) as $chunkDir) {
        removeChunkUpload($chunkDir, true);
    }

    return $deletedCount;
}

// Jeton CSRF pour les actions sensibles (ex : tout supprimer)
function getCsrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrfToken($token)
{
    return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Fonction pour s'assurer que le nettoyage n'est pas exécuté trop fréquemment
function shouldCleanFiles()
{
    $lastClean = isset($_SESSION['last_cleanup']) ? $_SESSION['last_cleanup'] : 0;
    $now = time();

    // Nettoyer au maximum toutes les 5 minutes
    if ($now - $lastClean > 300) {
        $_SESSION['last_cleanup'] = $now;
        return true;
    }

    return false;
}

// Convertit une valeur ini (ex: "20M", "1G") en nombre d'octets
function parseIniSize($size)
{
    $size = trim($size);
    $unit = strtoupper(substr($size, -1));
    $value = (float) $size;

    switch ($unit) {
        case 'G':
            $value *= 1024;
            // no break
        case 'M':
            $value *= 1024;
            // no break
        case 'K':
            $value *= 1024;
    }

    return (int) $value;
}

// Limites d'upload effectives du serveur (en octets), lues directement dans la config PHP
function getUploadLimits()
{
    return array(
        'file' => parseIniSize(ini_get('upload_max_filesize')),
        'post' => parseIniSize(ini_get('post_max_size')),
    );
}

// Message d'erreur lisible pour un code d'erreur $_FILES[...]['error']
function uploadErrorMessage($errorCode)
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "le fichier dépasse la taille maximale autorisée";
        case UPLOAD_ERR_PARTIAL:
            return "le fichier n'a été que partiellement envoyé";
        case UPLOAD_ERR_NO_FILE:
            return "aucun fichier n'a été envoyé";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            return "erreur serveur lors de l'enregistrement du fichier";
        default:
            return "erreur inconnue lors de l'upload";
    }
}

// Styles critiques du loader : inline dans le <head> pour s'afficher avant le chargement de styles.css
function renderLoaderHead()
{
    return '<style>'
        . 'html{background:#000}'
        // Scrollbars natives masquées dès le premier rendu (avant styles.css)
        . '*{scrollbar-width:none;-ms-overflow-style:none}*::-webkit-scrollbar{display:none;width:0;height:0}'
        // Pendant le chargement : pas de défilement ni de scrollbar custom
        . 'html.tn-preload{overflow:hidden}html.tn-preload .tn-scrollbar{display:none!important}'
        . 'html.tn-preload *,html.tn-preload *::before,html.tn-preload *::after{transition:none!important}'
        . '#tn-loader{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:#000;'
        . 'transition:opacity .35s ease,visibility 0s linear .35s!important}'
        . '#tn-loader.is-done{opacity:0;visibility:hidden}'
        . '.tn-loader-spinner{width:28px;height:28px;border:2px solid rgba(255,255,255,.15);border-top-color:#fff;border-radius:50%;'
        . 'animation:tn-spin .7s linear infinite}'
        . '@keyframes tn-spin{to{transform:rotate(360deg)}}'
        . '</style>';
}

// Écran de chargement, masqué par assets/ui.js une fois la page prête
function renderLoader()
{
    return '<div id="tn-loader" aria-hidden="true"><div class="tn-loader-spinner"></div></div>';
}

// Types MIME que le navigateur sait afficher sans risque (pas de HTML ni de SVG, qui pourraient exécuter du script)
const VIEWABLE_MIME_TYPES = array(
    'application/pdf',
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
    'image/avif',
    'image/bmp',
    'text/plain',
    'video/mp4',
    'video/webm',
    'audio/mpeg',
    'audio/ogg',
    'audio/wav',
    'audio/x-wav',
    'audio/webm',
    'audio/mp4',
);

function getFileMimeType($filepath)
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $filepath) ?: 'application/octet-stream';
    finfo_close($finfo);
    return $mimeType;
}

function isViewableMimeType($mimeType)
{
    return in_array($mimeType, VIEWABLE_MIME_TYPES, true);
}

// Renvoie l'URL si le texte est un unique lien http(s), sinon null
function getHttpUrl($text)
{
    $text = trim($text);
    if (preg_match('#^www\.#i', $text)) {
        $text = 'https://' . $text;
    }
    if ($text === '' || preg_match('/\s/', $text) || !preg_match('#^https?://#i', $text)) {
        return null;
    }
    return filter_var($text, FILTER_VALIDATE_URL) !== false ? $text : null;
}
