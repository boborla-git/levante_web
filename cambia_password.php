<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediLogin();

$errore = '';
$messaggio = '';

$obbligoCambioPassword = isset($_SESSION['deve_cambiare_password']) && (int)$_SESSION['deve_cambiare_password'] === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $passwordAttuale = (string)($_POST['password_attuale'] ?? '');
    $nuovaPassword = (string)($_POST['nuova_password'] ?? '');
    $confermaPassword = (string)($_POST['conferma_password'] ?? '');

    if ($nuovaPassword === '' || $confermaPassword === '') {
        $errore = 'Compila tutti i campi obbligatori.';
    } elseif ($nuovaPassword !== $confermaPassword) {
        $errore = 'La nuova password e la conferma non coincidono.';
    } else {
			$sql = "SELECT id, password_hash
					FROM utenti
					WHERE id = :id
					LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int)$_SESSION['utente_id']]);
        $utente = $stmt->fetch();

        if (!$utente) {
            $errore = 'Utente non trovato.';
        } else {
            $verificaPasswordAttuale = true;

            if (!$obbligoCambioPassword) {
                if ($passwordAttuale === '') {
                    $errore = 'Inserisci la password attuale.';
                    $verificaPasswordAttuale = false;
                } elseif (!password_verify($passwordAttuale, $utente['password_hash'])) {
                    $errore = 'La password attuale non è corretta.';
                    $verificaPasswordAttuale = false;
                }
            }

            if ($errore === '' && $verificaPasswordAttuale) {
                $nuovoHash = password_hash($nuovaPassword, PASSWORD_DEFAULT);

				$sqlUpdate = "UPDATE utenti
							  SET password_hash = :password_hash,
								  deve_cambiare_password = 0
							  WHERE id = :id";

                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    'password_hash' => $nuovoHash,
                    'id' => (int)$_SESSION['utente_id'],
                ]);

                $_SESSION['deve_cambiare_password'] = 0;
                $obbligoCambioPassword = false;
                $messaggio = 'Password aggiornata correttamente.';
            }
        }
    }
}

layoutHeader($obbligoCambioPassword ? 'Imposta una nuova password' : 'Cambia password');
?>

<div class="card card-form">
    <h1><?= $obbligoCambioPassword ? 'Imposta una nuova password' : 'Cambia password' ?></h1>

    <?php if ($obbligoCambioPassword): ?>
        <div class="meta" style="margin-bottom:18px;">
            Per motivi di sicurezza devi impostare una nuova password prima di continuare.
        </div>
    <?php endif; ?>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="post">
        <?php if (!$obbligoCambioPassword): ?>
            <div class="form-group">
                <label for="password_attuale">Password attuale</label>
                <input type="password" name="password_attuale" id="password_attuale" required>
            </div>
        <?php endif; ?>

        <div class="form-group">
            <label for="nuova_password">Nuova password</label>
            <input type="password" name="nuova_password" id="nuova_password" required>
        </div>

        <div class="form-group">
            <label for="conferma_password">Conferma nuova password</label>
            <input type="password" name="conferma_password" id="conferma_password" required>
        </div>

        <div class="actions">
            <button type="submit">Aggiorna password</button>
        </div>
    </form>

    <div class="links">
        <a href="index.php">Torna alla dashboard</a>
    </div>
</div>

<?php layoutFooter(); ?>