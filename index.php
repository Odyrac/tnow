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
                     l'affichage masqué est assuré par les cases ci-dessous -->
                <div class="tn-pin" id="pinBox">
                    <input type="text"
                        class="tn-pin-input"
                        id="pin"
                        name="pin"
                        maxlength="4"
                        pattern="[0-9]{4}"
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
                    <div class="tn-pin-cells" aria-hidden="true">
                        <span class="tn-pin-cell"></span>
                        <span class="tn-pin-cell"></span>
                        <span class="tn-pin-cell"></span>
                        <span class="tn-pin-cell"></span>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="tn-alert tn-pin-error"><?php echo $error; ?></div>
                <?php endif; ?>

            </div>
            <button type="submit" class="tn-btn tn-btn-primary tn-btn-block">Valider</button>
        </form>
    </div>

    <script src="assets/ui.js"></script>
    <script>
        const pinInput = document.getElementById('pin');
        const pinBox = document.getElementById('pinBox');
        const pinCells = pinBox.querySelectorAll('.tn-pin-cell');

        // Affiche un point par chiffre saisi et met en évidence la case courante
        function renderPin() {
            pinInput.value = pinInput.value.replace(/[^0-9]/g, '').slice(0, 4);
            const length = pinInput.value.length;
            const focused = document.activeElement === pinInput;
            pinCells.forEach((cell, i) => {
                cell.classList.toggle('is-filled', i < length);
                cell.classList.toggle('is-current', focused && i === Math.min(length, 3));
            });
        }

        pinInput.addEventListener('input', renderPin);
        pinInput.addEventListener('focus', renderPin);
        pinInput.addEventListener('blur', renderPin);
        // Curseur toujours en fin de saisie
        pinInput.addEventListener('select', () => {
            const end = pinInput.value.length;
            pinInput.setSelectionRange(end, end);
        });
        renderPin();

        pinInput.addEventListener('keypress', function(event) {
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