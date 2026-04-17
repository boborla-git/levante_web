<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $nome = trim((string)($_POST['nome'] ?? ''));
    $ruolo = trim((string)($_POST['ruolo'] ?? 'user'));
    $password = (string)($_POST['password'] ?? '');
    $confermaPassword = (string)($_POST['conferma_password'] ?? '');
    $attivo = isset($_POST['attivo']) ? 1 : 0;

    if ($username === '' || $password === '') {
        $errore = 'Username e password sono obbligatori.';
    } elseif ($password !== $confermaPassword) {
        $errore = 'La password e la conferma non coincidono.';
    } elseif (!in_array($ruolo, ['admin', 'user'], true)) {
        $errore = 'Ruolo non valido.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

		$sql = "INSERT INTO utenti (username, password_hash, nome, ruolo, attivo, deve_cambiare_password)
				VALUES (:username, :password_hash, :nome, :ruolo, :attivo, :deve_cambiare_password)";

        $stmt = $pdo->prepare($sql);

        try {
            $stmt->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'nome' => $nome !== '' ? $nome : null,
                'ruolo' => $ruolo,
                'attivo' => $attivo,
                'deve_cambiare_password' => 1,
            ]);

            header('Location: utenti.php');
            exit;
        } catch (Throwable $e) {
            $errore = 'Impossibile creare l’utente. Verifica che lo username non esista già.';
        }
    }
}

layoutHeader('Nuovo utente');
?>

<div class="card card-form">
    <h1>Nuovo utente</h1>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" name="username" id="username" required>
        </div>

        <div class="form-group">
            <label for="nome">Nome</label>
            <input type="text" name="nome" id="nome">
        </div>

        <div class="form-group">
            <label for="ruolo">Ruolo</label>
            <select name="ruolo" id="ruolo">
                <option value="user">user</option>
                <option value="admin">admin</option>
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
                <input type="checkbox" name="attivo" value="1" checked>
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