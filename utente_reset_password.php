<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$idUtente = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id_utente'] ?? 0);

if ($idUtente <= 0) {
    header('Location: utenti.php');
    exit;
}

$errore = '';
$messaggio = '';

$stmtUtente = $pdo->prepare("
    SELECT
        id_utente,
        username,
        nome,
        cognome,
        email,
        attivo
    FROM aut_utenti
    WHERE id_utente = :id_utente
    LIMIT 1
");
$stmtUtente->execute([
    'id_utente' => $idUtente,
]);
$utente = $stmtUtente->fetch(PDO::FETCH_ASSOC);

if (!$utente) {
    http_response_code(404);
    die('Utente non trovato.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nuovaPassword = (string)($_POST['nuova_password'] ?? '');
    $confermaPassword = (string)($_POST['conferma_password'] ?? '');
    $obbligaCambio = isset($_POST['deve_cambiare_password']) ? 1 : 0;

    if ($nuovaPassword === '' || $confermaPassword === '') {
        $errore = 'Compila tutti i campi obbligatori.';
    } elseif ($nuovaPassword !== $confermaPassword) {
        $errore = 'La nuova password e la conferma non coincidono.';
    } elseif (mb_strlen($nuovaPassword) < 6) {
        $errore = 'La nuova password deve contenere almeno 6 caratteri.';
    } else {
        try {
            $nuovoHash = password_hash($nuovaPassword, PASSWORD_DEFAULT);

            if ($nuovoHash === false) {
                throw new RuntimeException('Impossibile generare l\'hash della password.');
            }

            $stmtUpdate = $pdo->prepare("
                UPDATE aut_utenti
                SET
                    password_hash = :password_hash,
                    deve_cambiare_password = :deve_cambiare_password,
                    data_aggiornamento = NOW()
                WHERE id_utente = :id_utente
            ");

            $stmtUpdate->execute([
                'password_hash' => $nuovoHash,
                'deve_cambiare_password' => $obbligaCambio,
                'id_utente' => $idUtente,
            ]);

            if ((int)$utente['id_utente'] === (int)($_SESSION['utente_id'] ?? 0)) {
                $_SESSION['deve_cambiare_password'] = $obbligaCambio;
            }

            $messaggio = 'Password aggiornata correttamente.';
        } catch (Throwable $e) {
            $errore = 'Errore durante il reset della password.';
        }
    }
}

layoutHeader('Reset password utente');
?>

<div class="card card-form">
    <h1>Admin</h1>

    <h2>Reset password utente</h2>

    <div class="meta" style="margin-bottom:18px;">
        <strong>Username:</strong> <?= htmlspecialchars((string)$utente['username']) ?><br>
        <strong>Nome:</strong> <?= htmlspecialchars(trim(((string)$utente['nome']) . ' ' . ((string)$utente['cognome']))) ?><br>
        <strong>Stato:</strong> <?= (int)$utente['attivo'] === 1 ? 'Attivo' : 'Disattivo' ?>
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="id_utente" value="<?= (int)$utente['id_utente'] ?>">

        <div class="form-group">
            <label for="nuova_password">Nuova password temporanea</label>
            <input
                type="password"
                name="nuova_password"
                id="nuova_password"
                required
            >
        </div>

        <div class="form-group">
            <label for="conferma_password">Conferma nuova password</label>
            <input
                type="password"
                name="conferma_password"
                id="conferma_password"
                required
            >
        </div>

        <div class="form-group">
            <label style="display:flex; align-items:center; gap:8px;">
                <input
                    type="checkbox"
                    name="deve_cambiare_password"
                    value="1"
                    checked
                    style="width:auto;"
                >
                Obbliga il cambio password al prossimo accesso
            </label>
        </div>

        <div class="actions">
            <button type="submit">Salva nuova password</button>
        </div>
    </form>

    <div class="links">
        <a href="utenti.php">Torna alla gestione utenti</a>
    </div>
</div>

<?php layoutFooter(); ?>