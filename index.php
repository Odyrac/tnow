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
                     l'affichage masqué (un point par caractère, sans longueur fixe) est assuré en dessous -->
                <div class="tn-pin" id="pinBox">
                    <input type="text"
                        class="tn-pin-input"
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
                    <div class="tn-pin-display" aria-hidden="true">
                        <div class="tn-pin-dots" id="pinDots"><span class="tn-pin-caret"></span></div>
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
        const pinDots = document.getElementById('pinDots');
        const pinCaret = pinDots.querySelector('.tn-pin-caret');

        // Un point par caractère saisi, quelle que soit la longueur du code
        function renderPin() {
            const length = pinInput.value.length;
            const dots = pinDots.querySelectorAll('.tn-pin-dot');

            for (let i = dots.length; i < length; i++) {
                const dot = document.createElement('span');
                dot.className = 'tn-pin-dot';
                pinDots.insertBefore(dot, pinCaret);
            }
            for (let i = dots.length - 1; i >= length; i--) {
                dots[i].remove();
            }

            pinBox.classList.toggle('is-focused', document.activeElement === pinInput);
            // Si le code dépasse la largeur, on garde la fin (et le curseur) visible
            pinDots.classList.toggle('is-overflowing', pinDots.scrollWidth > pinDots.clientWidth);
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

        pinInput.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.value.length > 0) {
                    document.getElementById('loginForm').submit();
                }
            }
        });
    </script>
</body>

</html>