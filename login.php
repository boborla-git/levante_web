<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $errore = 'Inserisci username e password.';
    } else {
        $sql = "SELECT id, username, password_hash, nome, ruolo, attivo, deve_cambiare_password
                FROM utenti
                WHERE username = :username
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['username' => $username]);
        $utente = $stmt->fetch();

        if (!$utente || (int)$utente['attivo'] !== 1) {
            $errore = 'Credenziali non valide.';
        } elseif (!password_verify($password, $utente['password_hash'])) {
            $errore = 'Credenziali non valide.';
        } else {
            session_regenerate_id(true);

            $_SESSION['utente_id'] = (int)$utente['id'];
            $_SESSION['username'] = $utente['username'];
            $_SESSION['nome'] = $utente['nome'];
            $_SESSION['ruolo'] = $utente['ruolo'];
            $_SESSION['deve_cambiare_password'] = (int)$utente['deve_cambiare_password'];

            $sqlPermessi = "SELECT m.codice, up.livello
                            FROM utenti_permessi up
                            INNER JOIN moduli m ON m.id = up.modulo_id
                            WHERE up.utente_id = :utente_id
                              AND m.attivo = 1";

            $stmtPermessi = $pdo->prepare($sqlPermessi);
            $stmtPermessi->execute(['utente_id' => (int)$utente['id']]);

            $permessi = [];
            while ($rigaPermesso = $stmtPermessi->fetch()) {
                $permessi[$rigaPermesso['codice']] = $rigaPermesso['livello'];
            }

            $_SESSION['permessi'] = $permessi;

            if ((int)$utente['deve_cambiare_password'] === 1) {
                header('Location: cambia_password.php');
                exit;
            }

            header('Location: index.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Levante - Accesso</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="icon" type="image/png" href="/assets/favicon.png">
</head>
<body class="login-page">
    <div class="login-panel">
        <div class="login-logo">
            <img src="/assets/img/logo-ravioli.png" alt="Ravioli S.p.A.">
        </div>

        <h1 class="login-title">Accedi a Levante</h1>

        <div class="login-card">
            <?php if ($errore !== ''): ?>
                <div class="errore"><?= htmlspecialchars($errore) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" name="username" id="username" required autofocus>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" name="password" id="password" required>
                </div>

                <button type="submit" class="btn">Accedi</button>
            </form>
        </div>

        <div class="login-note">Accesso riservato agli utenti autorizzati.</div>
    </div>
</body>
</html>
