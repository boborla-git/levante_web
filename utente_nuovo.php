<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';

$username = trim((string)($_POST['username'] ?? ''));
$nome = trim((string)($_POST['nome'] ?? ''));
$cognome = trim((string)($_POST['cognome'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$idRuolo = (int)($_POST['id_ruolo'] ?? 0);
$attivo = isset($_POST['attivo']) || $_SERVER['REQUEST_METHOD'] !== 'POST' ? 1 : 0;

// Ruoli disponibili dal nuovo sistema
try {
    $stmtRuoli = $pdo->query("
        SELECT id_ruolo, codice_ruolo, descrizione
        FROM aut_ruoli
        WHERE attivo = 1
        ORDER BY ordinamento, codice_ruolo
    ");
    $ruoliDisponibili = $stmtRuoli->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei ruoli disponibili.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string)($_POST['password'] ?? '');
    $confermaPassword = (string)($_POST['conferma_password'] ?? '');
    $nomeCompletoLegacy = trim($nome . ' ' . $cognome);

    if ($username === '' || $password === '') {
        $errore = 'Username e password sono obbligatori.';
    } elseif ($nome === '' || $cognome === '') {
        $errore = 'Nome e cognome sono obbligatori.';
    } elseif ($password !== $confermaPassword) {
        $errore = 'La password e la conferma non coincidono.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email non valida.';
    } else {
        try {
            $pdo->beginTransaction();

            $ruoloCodice = null;
            if ($idRuolo > 0) {
                $stmtRuolo = $pdo->prepare("
                    SELECT codice_ruolo
                    FROM aut_ruoli
                    WHERE id_ruolo = :id_ruolo
                      AND attivo = 1
                ");
                $stmtRuolo->execute(['id_ruolo' => $idRuolo]);
                $ruoloCodice = $stmtRuolo->fetchColumn();

                if ($ruoloCodice === false) {
                    throw new RuntimeException('Ruolo selezionato non valido.');
                }
            }

            $stmtTipoUtente = $pdo->query("
                SELECT id_tipo_utente
                FROM aut_tipi_utente
                WHERE codice = 'interno'
                LIMIT 1
            ");
            $idTipoUtente = (int)$stmtTipoUtente->fetchColumn();

            if ($idTipoUtente <= 0) {
                throw new RuntimeException('Tipo utente interno non trovato.');
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            if ($passwordHash === false) {
                throw new RuntimeException('Impossibile generare hash password.');
            }

            // Compatibilità legacy: admin_portale => admin, tutto il resto => user
            $ruoloLegacy = ($ruoloCodice === 'admin_portale') ? 'admin' : 'user';

            // Inserimento nel sistema legacy
            $stmtLegacy = $pdo->prepare("
                INSERT INTO utenti
                (
                    username,
                    password_hash,
                    nome,
                    email,
                    ruolo,
                    attivo,
                    deve_cambiare_password,
                    creato_il,
                    aggiornato_il
                )
                VALUES
                (
                    :username,
                    :password_hash,
                    :nome,
                    :email,
                    :ruolo,
                    :attivo,
                    1,
                    NOW(),
                    NOW()
                )
            ");

            $stmtLegacy->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'nome' => $nomeCompletoLegacy,
                'email' => $email !== '' ? $email : null,
                'ruolo' => $ruoloLegacy,
                'attivo' => $attivo,
            ]);

            $idUtente = (int)$pdo->lastInsertId();

            if ($idUtente <= 0) {
                throw new RuntimeException('Impossibile recuperare ID utente creato.');
            }

            // Inserimento nel nuovo sistema
            $stmtAut = $pdo->prepare("
                INSERT INTO aut_utenti
                (
                    id_utente,
                    username,
                    password_hash,
                    email,
                    nome,
                    cognome,
                    id_tipo_utente,
                    attivo,
                    lingua_preferita,
                    locale_preferito,
                    fuso_orario,
                    data_creazione,
                    note,
                    email_notifiche,
                    sms_notifiche,
                    email_verificata,
                    telefono_verificato
                )
                VALUES
                (
                    :id_utente,
                    :username,
                    :password_hash,
                    :email,
                    :nome,
                    :cognome,
                    :id_tipo_utente,
                    :attivo,
                    'it',
                    'it-IT',
                    'Europe/Rome',
                    NOW(),
                    'Creato da interfaccia web',
                    1,
                    0,
                    0,
                    0
                )
            ");

            $stmtAut->execute([
                'id_utente' => $idUtente,
                'username' => $username,
                'password_hash' => $passwordHash,
                'email' => $email !== '' ? $email : null,
                'nome' => $nome,
                'cognome' => $cognome,
                'id_tipo_utente' => $idTipoUtente,
                'attivo' => $attivo,
            ]);

            if ($idRuolo > 0) {
                $stmtRuoloUtente = $pdo->prepare("
                    INSERT INTO aut_utenti_ruoli
                    (
                        id_utente,
                        id_ruolo,
                        data_inizio,
                        data_fine,
                        attivo
                    )
                    VALUES
                    (
                        :id_utente,
                        :id_ruolo,
                        NOW(),
                        NULL,
                        1
                    )
                ");
                $stmtRuoloUtente->execute([
                    'id_utente' => $idUtente,
                    'id_ruolo' => $idRuolo,
                ]);
            }

            $pdo->commit();

            header('Location: utenti.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errore = 'Impossibile creare l\'utente. Verifica che username o email non esistano già e che il ruolo sia valido.';
        }
    }
}

layoutHeader('Nuovo utente');
?>

<div class="card card-form">
    <h1>Admin</h1>

    <h2>Nuovo utente</h2>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label for="username">Username</label>
            <input
                type="text"
                name="username"
                id="username"
                value="<?= htmlspecialchars($username) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="nome">Nome</label>
            <input
                type="text"
                id="nome"
                name="nome"
                value="<?= htmlspecialchars($nome) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="cognome">Cognome</label>
            <input
                type="text"
                id="cognome"
                name="cognome"
                value="<?= htmlspecialchars($cognome) ?>"
                required
            >
        </div>

        <div class="form-group">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars($email) ?>"
            >
        </div>

        <div class="form-group">
            <label for="id_ruolo">Ruolo attivo</label>
            <select name="id_ruolo" id="id_ruolo">
                <option value="0">nessun ruolo</option>
                <?php foreach ($ruoliDisponibili as $ruolo): ?>
                    <option
                        value="<?= (int)$ruolo['id_ruolo'] ?>"
                        <?= $idRuolo === (int)$ruolo['id_ruolo'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars((string)$ruolo['codice_ruolo']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="password">Password iniziale</label>
            <input type="password" name="password" id="password" required>
        </div>

        <div class="form-group">
            <label for="conferma_password">Conferma password</label>
            <input type="password" name="conferma_password" id="conferma_password" required>
        </div>

        <div class="checkbox-row">
            <label>
                <input type="checkbox" name="attivo" value="1" <?= $attivo === 1 ? 'checked' : '' ?>>
                Utente attivo
            </label>
        </div>

        <div class="actions">
            <button type="submit">Crea utente</button>
        </div>
    </form>

    <div class="links">
        <a href="utenti.php">Torna alla gestione utenti</a>
    </div>
</div>

<?php layoutFooter(); ?>