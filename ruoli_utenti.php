<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';
$messaggio = '';

try {
    $stmtUtenti = $pdo->query("
        SELECT id_utente, username, nome, cognome, attivo
        FROM aut_utenti
        ORDER BY username
    ");
    $utenti = $stmtUtenti->fetchAll();

    $stmtRuoli = $pdo->query("
        SELECT id_ruolo, codice_ruolo, descrizione, attivo, ordinamento
        FROM aut_ruoli
        WHERE attivo = 1
        ORDER BY ordinamento, codice_ruolo
    ");
    $ruoli = $stmtRuoli->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di utenti o ruoli.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        foreach ($utenti as $utente) {
            $utenteId = (int)$utente['id_utente'];
            $chiave = 'ruolo_utente_' . $utenteId;
            $idRuoloSelezionato = (int)($_POST[$chiave] ?? 0);

            $stmtDisattiva = $pdo->prepare("
                UPDATE aut_utenti_ruoli
                SET attivo = 0,
                    data_fine = NOW()
                WHERE id_utente = :id_utente
                  AND attivo = 1
            ");
            $stmtDisattiva->execute([
                'id_utente' => $utenteId,
            ]);

            if ($idRuoloSelezionato > 0) {
                $stmtInserisci = $pdo->prepare("
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
                    ON DUPLICATE KEY UPDATE
                        attivo = 1,
                        data_inizio = NOW(),
                        data_fine = NULL
                ");
                $stmtInserisci->execute([
                    'id_utente' => $utenteId,
                    'id_ruolo' => $idRuoloSelezionato,
                ]);
            }
        }

        $pdo->commit();
        $messaggio = 'Ruoli utenti aggiornati correttamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errore = 'Errore durante il salvataggio dei ruoli utenti.';
    }
}

$ruoloUtenteMappa = [];

try {
    $stmtRuoliUtenti = $pdo->query("
        SELECT id_utente, id_ruolo
        FROM aut_utenti_ruoli
        WHERE attivo = 1
    ");

    while ($riga = $stmtRuoliUtenti->fetch()) {
        $ruoloUtenteMappa[(int)$riga['id_utente']] = (int)$riga['id_ruolo'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei ruoli utente.');
}

layoutHeader('Ruoli utenti');
?>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="admin-tabs">
        <span class="admin-tabs-label">Sezione:</span>
        <a href="utenti.php"><i class="la la-users" aria-hidden="true"></i> Utenti</a>
        <a class="active" href="ruoli_utenti.php"><i class="la la-user-tag" aria-hidden="true"></i> Ruoli utenti</a>
        <a href="permessi_ruoli.php"><i class="la la-key" aria-hidden="true"></i> Permessi ruoli</a>
    </div>

    <h2>Ruoli utenti</h2>

    <div class="meta" style="margin-bottom:18px;">
        Ogni utente eredita i permessi dal ruolo attivo assegnato.
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="post">
        <table>
            <thead>
                <tr>
                    <th>Utente</th>
                    <th>Nome</th>
                    <th>Stato</th>
                    <th>Ruolo attivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($utenti as $utente): ?>
                    <?php
                    $utenteId = (int)$utente['id_utente'];
                    $chiave = 'ruolo_utente_' . $utenteId;
                    $ruoloCorrente = (int)($ruoloUtenteMappa[$utenteId] ?? 0);
                    $nomeCompleto = trim(((string)$utente['nome']) . ' ' . ((string)$utente['cognome']));
                    ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$utente['username']) ?></td>
                        <td><?= htmlspecialchars($nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username']) ?></td>
                        <td>
                            <?php if ((int)$utente['attivo'] === 1): ?>
                                <span class="stato-attivo">Attivo</span>
                            <?php else: ?>
                                <span class="stato-disattivo">Disattivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <select name="<?= htmlspecialchars($chiave) ?>">
                                <option value="0" <?= $ruoloCorrente === 0 ? 'selected' : '' ?>>nessun ruolo</option>
                                <?php foreach ($ruoli as $ruolo): ?>
                                    <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= $ruoloCorrente === (int)$ruolo['id_ruolo'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$ruolo['codice_ruolo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="actions">
            <button type="submit"><i class="la la-save" aria-hidden="true"></i> Salva ruoli utenti</button>
        </div>
    </form>

    <div class="links">
        <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla dashboard</a>
    </div>
</div>

<?php layoutFooter(); ?>
