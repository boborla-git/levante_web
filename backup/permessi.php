<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';
$messaggio = '';

$livelliValidi = ['none', 'read', 'write'];

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

    $stmtRisorse = $pdo->query("
        SELECT id_risorsa, codice_risorsa, descrizione, tipo_risorsa, attivo, ordinamento
        FROM aut_risorse
        WHERE attivo = 1
          AND tipo_risorsa = 'pagina'
        ORDER BY ordinamento, codice_risorsa
    ");
    $risorse = $stmtRisorse->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di utenti, ruoli o risorse.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = (string)($_POST['azione'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($azione === 'salva_ruoli_utenti') {
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

            $messaggio = 'Ruoli utenti aggiornati correttamente.';
        }

        if ($azione === 'salva_permessi_ruoli') {
            foreach ($ruoli as $ruolo) {
                $idRuolo = (int)$ruolo['id_ruolo'];

                foreach ($risorse as $risorsa) {
                    $idRisorsa = (int)$risorsa['id_risorsa'];
                    $chiave = 'permesso_ruolo_' . $idRuolo . '_' . $idRisorsa;
                    $livello = (string)($_POST[$chiave] ?? 'none');

                    if (!in_array($livello, $livelliValidi, true)) {
                        $livello = 'none';
                    }

                    $viewConsentito = ($livello === 'read' || $livello === 'write') ? 1 : 0;
                    $editConsentito = ($livello === 'write') ? 1 : 0;

                    $stmtView = $pdo->prepare("
                        INSERT INTO aut_ruoli_permessi
                        (
                            id_ruolo,
                            id_risorsa,
                            permesso,
                            consentito
                        )
                        VALUES
                        (
                            :id_ruolo,
                            :id_risorsa,
                            'view',
                            :consentito
                        )
                        ON DUPLICATE KEY UPDATE
                            consentito = VALUES(consentito)
                    ");
                    $stmtView->execute([
                        'id_ruolo' => $idRuolo,
                        'id_risorsa' => $idRisorsa,
                        'consentito' => $viewConsentito,
                    ]);

                    $stmtEdit = $pdo->prepare("
                        INSERT INTO aut_ruoli_permessi
                        (
                            id_ruolo,
                            id_risorsa,
                            permesso,
                            consentito
                        )
                        VALUES
                        (
                            :id_ruolo,
                            :id_risorsa,
                            'edit',
                            :consentito
                        )
                        ON DUPLICATE KEY UPDATE
                            consentito = VALUES(consentito)
                    ");
                    $stmtEdit->execute([
                        'id_ruolo' => $idRuolo,
                        'id_risorsa' => $idRisorsa,
                        'consentito' => $editConsentito,
                    ]);
                }
            }

            $messaggio = 'Permessi ruoli aggiornati correttamente.';
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errore = 'Errore durante il salvataggio.';
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

$permessiRuoliMappa = [];

try {
    $stmtPermessi = $pdo->query("
        SELECT id_ruolo, id_risorsa, permesso, consentito
        FROM aut_ruoli_permessi
    ");

    while ($riga = $stmtPermessi->fetch()) {
        $idRuolo = (int)$riga['id_ruolo'];
        $idRisorsa = (int)$riga['id_risorsa'];
        $permesso = (string)$riga['permesso'];
        $consentito = (int)$riga['consentito'];

        $permessiRuoliMappa[$idRuolo][$idRisorsa][$permesso] = $consentito;
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei permessi dei ruoli.');
}

function livelloCorrenteRuolo(array $permessiRuoliMappa, int $idRuolo, int $idRisorsa): string
{
    $view = (int)($permessiRuoliMappa[$idRuolo][$idRisorsa]['view'] ?? 0);
    $edit = (int)($permessiRuoliMappa[$idRuolo][$idRisorsa]['edit'] ?? 0);

    if ($view === 1 && $edit === 1) {
        return 'write';
    }

    if ($view === 1) {
        return 'read';
    }

    return 'none';
}

layoutHeader('Gestione ruoli e permessi');
?>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="links" style="margin-bottom:18px;">
        <strong>Sezione:</strong>
        <a href="utenti.php">Utenti</a>
        &nbsp;•&nbsp;
        <a href="permessi.php"><strong>Ruoli e permessi</strong></a>
    </div>

    <h2>Gestione ruoli e permessi</h2>

    <div class="meta" style="margin-bottom:18px;">
        Il sistema assegna i permessi ai ruoli. Gli utenti ereditano i permessi dal ruolo attivo assegnato.
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <h2 style="margin-top: 28px;">Utenti e ruoli</h2>

    <form method="post" style="margin-bottom: 32px;">
        <input type="hidden" name="azione" value="salva_ruoli_utenti">

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
            <button type="submit">Salva ruoli utenti</button>
        </div>
    </form>

    <h2>Permessi dei ruoli</h2>

    <form method="post">
        <input type="hidden" name="azione" value="salva_permessi_ruoli">

        <table>
            <thead>
                <tr>
                    <th>Ruolo</th>
                    <?php foreach ($risorse as $risorsa): ?>
                        <th><?= htmlspecialchars((string)$risorsa['descrizione']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ruoli as $ruolo): ?>
                    <?php $idRuolo = (int)$ruolo['id_ruolo']; ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string)$ruolo['codice_ruolo']) ?></strong>
                        </td>

                        <?php foreach ($risorse as $risorsa): ?>
                            <?php
                            $idRisorsa = (int)$risorsa['id_risorsa'];
                            $chiave = 'permesso_ruolo_' . $idRuolo . '_' . $idRisorsa;
                            $livelloCorrente = livelloCorrenteRuolo($permessiRuoliMappa, $idRuolo, $idRisorsa);
                            ?>
                            <td>
                                <select name="<?= htmlspecialchars($chiave) ?>">
                                    <option value="none" <?= $livelloCorrente === 'none' ? 'selected' : '' ?>>none</option>
                                    <option value="read" <?= $livelloCorrente === 'read' ? 'selected' : '' ?>>read</option>
                                    <option value="write" <?= $livelloCorrente === 'write' ? 'selected' : '' ?>>write</option>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="actions">
            <button type="submit">Salva permessi ruoli</button>
        </div>
    </form>

    <div class="links">
        <a href="index.php">Torna alla dashboard</a>
    </div>
</div>

<?php layoutFooter(); ?>