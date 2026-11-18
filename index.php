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
<html lang="fr" class="tn-preload">

<head>
    <meta charset="UTF-8">
    <?php echo renderLoaderHead(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Now</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="icon" type="image/png" href="assets/logo.png" />
</head>

<body>
    <?php echo renderLoader(); ?>
    <div class="login-container">
        <div class="title-container">
            <img src="assets/logo.png" alt="Logo" class="logo" draggable="false">
            <h2>Transfer Now</h2>
        </div>
        <form method="POST" id="loginForm">
            <div class="tn-field">
                <label for="pin" class="tn-label tn-label-center">Entrez le code PIN :</label>
                <!-- Champ texte (pas "password") pour ne déclencher aucune suggestion de mot de passe ;
                     le masquage est assuré en CSS (-webkit-text-security) -->
                <input type="text"
                    class="tn-input tn-pin-input"
                    id="pin"
                    name="pin"
                    inputmode="numeric"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="off"
                    spellcheck="false"
                    data-lpignore="true"
                    data-1p-ignore="true"
                    data-bwignore="true"
                    data-form-type="other"
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
        // Navigateurs sans -webkit-text-security : repli sur un vrai champ masqué
        if (!CSS.supports('-webkit-text-security', 'disc')) {
            document.getElementById('pin').type = 'password';
        }
    </script>
</body>

</html>