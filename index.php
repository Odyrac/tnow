<?php
session_start();
require_once 'functions.php';
require_once 'config.php';

// Redirection si déjà connecté
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header("Location: transfer.php");
    exit;
}

// Nettoyage périodique des fichiers
if (shouldCleanFiles()) {
    cleanOldFiles();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $pin = $_POST['pin'];
    if ($pin === ACCESS_PIN) {
        $_SESSION['authenticated'] = true;
        header("Location: transfer.php");
        exit;
    } else {
        $error = "Code PIN incorrect !";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Now</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png" />
</head>

<body>
    <div class="login-container">
        <div class="title-container">
            <img src="assets/logo.png" alt="Logo" class="logo" draggable="false">
            <h2>Transfer Now</h2>
        </div>
        <form method="POST" id="loginForm">
            <div class="tn-field">
                <label for="pin" class="tn-label">Entrez le code PIN :</label>
                <input type="password"
                    class="tn-input tn-pin"
                    id="pin"
                    name="pin"
                    maxlength="4"
                    pattern="[0-9]{4}"
                    inputmode="numeric"
                    autocomplete="off"
                    onkeypress="return event.charCode >= 48 && event.charCode <= 57"
                    required
                    autofocus>

                <?php if (isset($error)): ?>
                    <div class="tn-alert tn-pin-error"><?php echo $error; ?></div>
                <?php endif; ?>

            </div>
            <button type="submit" class="tn-btn tn-btn-primary tn-btn-block">Valider</button>
        </form>
    </div>

    <script src="assets/ui.js"></script>
    <script>
        document.getElementById('pin').addEventListener('keypress', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.value.length === 4) {
                    document.getElementById('loginForm').submit();
                }
            }
        });
    </script>
</body>

</html>