<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('utenti');

$stmt = $pdo->query("
    SELECT
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento,
        ar.codice_ruolo AS ruolo_attivo
    FROM aut_utenti u
    LEFT JOIN aut_utenti_ruoli aur
        ON aur.id_utente = u.id_utente
       AND aur.attivo = 1
       AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
    LEFT JOIN aut_ruoli ar
        ON ar.id_ruolo = aur.id_ruolo
       AND ar.attivo = 1
    ORDER BY u.username
");
$utenti = $stmt->fetchAll();

layoutHeader('Gestione utenti');
?>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="links" style="margin-bottom:18px;">
        <strong>Sezione:</strong>
        <a href="utenti.php"><strong>Utenti</strong></a>
        &nbsp;|&nbsp;
        <a href="ruoli_utenti.php">Ruoli utenti</a>
        &nbsp;|&nbsp;
        <a href="permessi_ruoli.php">Permessi ruoli</a>
    </div>

    <h2>Gestione utenti</h2>

    <div class="actions">
        <a class="btn" href="utente_nuovo.php">Nuovo utente</a>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Nome e cognome</th>
                <th>Ruolo attivo</th>
                <th>Stato</th>
                <th>Cambio password</th>
                <th>Creato il</th>
                <th>Aggiornato il</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($utenti as $utente): ?>
                <?php $nomeCompleto = trim(((string)$utente['nome']) . ' ' . ((string)$utente['cognome'])); ?>
                <tr>
                    <td><?= (int)$utente['id_utente'] ?></td>
                    <td><?= htmlspecialchars((string)$utente['username']) ?></td>
                    <td><?= htmlspecialchars($nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username']) ?></td>
                    <td><?= htmlspecialchars((string)($utente['ruolo_attivo'] ?? 'nessun ruolo')) ?></td>
                    <td>
                        <?php if ((int)$utente['attivo'] === 1): ?>
                            <span class="stato-attivo">Attivo</span>
                        <?php else: ?>
                            <span class="stato-disattivo">Disattivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$utente['deve_cambiare_password'] === 1): ?>
                            <span class="stato-disattivo">Obbligatorio</span>
                        <?php else: ?>
                            <span class="stato-attivo">No</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)$utente['data_creazione']) ?></td>
                    <td><?= htmlspecialchars((string)($utente['data_aggiornamento'] ?? '')) ?></td>
                    <td>
                        <?php if ((int)$utente['id_utente'] !== (int)($_SESSION['utente_id'] ?? 0)): ?>
                            <a href="utente_reset_password.php?id=<?= (int)$utente['id_utente'] ?>">
                                Reset password
                            </a>
                            <br>
                            <a href="utente_forza_password.php?id=<?= (int)$utente['id_utente'] ?>"
                               onclick="return confirm('Vuoi obbligare questo utente a cambiare la password al prossimo accesso?');">
                                Forza cambio password
                            </a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="links">
        <a href="index.php">Torna alla dashboard</a>
    </div>
</div>

<?php layoutFooter(); ?>