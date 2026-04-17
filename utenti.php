<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('utenti');

$stmt = $pdo->query("
    SELECT
        u.id,
        u.username,
        u.nome,
        u.attivo,
        u.deve_cambiare_password,
        u.creato_il,
        u.aggiornato_il,
        ar.codice_ruolo AS ruolo_attivo
    FROM utenti u
    LEFT JOIN aut_utenti au
        ON au.id_utente = u.id
    LEFT JOIN aut_utenti_ruoli aur
        ON aur.id_utente = au.id_utente
       AND aur.attivo = 1
    LEFT JOIN aut_ruoli ar
        ON ar.id_ruolo = aur.id_ruolo
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
                <th>Nome</th>
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
                <tr>
                    <td><?= (int)$utente['id'] ?></td>
                    <td><?= htmlspecialchars((string)$utente['username']) ?></td>
                    <td><?= htmlspecialchars((string)$utente['nome']) ?></td>
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
                    <td><?= htmlspecialchars((string)$utente['creato_il']) ?></td>
                    <td><?= htmlspecialchars((string)$utente['aggiornato_il']) ?></td>
                    <td>
                        <?php if ((int)$utente['id'] !== (int)$_SESSION['utente_id']): ?>
                            <a href="utente_forza_password.php?id=<?= (int)$utente['id'] ?>"
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
