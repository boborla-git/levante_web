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
        SELECT
            id_utente,
            username,
            nome,
            cognome,
            attivo
        FROM aut_utenti
        ORDER BY username
    ");
    $utenti = $stmtUtenti->fetchAll();

    $stmtRuoli = $pdo->query("
        SELECT
            id_ruolo,
            codice_ruolo,
            descrizione,
            attivo,
            ordinamento
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
                SET
                    attivo = 0,
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

function classeBadgeRuoloUtente(bool $attivo): string
{
    return $attivo ? 'status-ok' : 'status-neutral';
}

layoutHeader('Ruoli utenti');
?>

<style>
.hr-filter-toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) minmax(280px, 420px);
    gap: 16px;
    align-items: end;
    margin-bottom: 16px;
}
.hr-filter-toolbar-main {
    min-width: 0;
}
.hr-filter-search-group {
    margin-bottom: 0;
}
.hr-filter-search-group input[type="search"] {
    width: 100%;
    min-height: 40px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    font: inherit;
    color: #0f172a;
    background: #fff;
    box-sizing: border-box;
}
.hr-filter-search-group input[type="search"]:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.16);
}
.role-select {
    width: 100%;
    min-width: 220px;
}
.user-badge {
    white-space: nowrap;
}
.admin-save-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
}

.admin-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-tabs-label {
    white-space: nowrap;
}
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.table-wrap table {
    min-width: 760px;
}
@media (max-width: 900px) {
    .admin-tabs {
        align-items: stretch;
    }
    .admin-tabs-label {
        width: 100%;
    }
    .admin-tabs a {
        flex: 1 1 auto;
        justify-content: center;
        text-align: center;
    }
    .hr-filter-toolbar {
        gap: 12px;
    }
    .hr-filter-search-group input[type="search"] {
        min-height: 42px;
    }
}
@media (max-width: 520px) {
    .card.card-wide {
        padding: 16px;
    }
    .admin-tabs a {
        width: 100%;
    }
    .hr-filter-toolbar-main h2 {
        margin-top: 0;
    }
    .table-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: stretch;
    }
    .table-actions .btn {
        width: 100%;
        justify-content: center;
        white-space: nowrap;
    }
}

@media (max-width: 900px) {
    .hr-filter-toolbar {
        grid-template-columns: 1fr;
    }
    .admin-save-actions .btn,
    .admin-save-actions button {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="admin-tabs">
        <span class="admin-tabs-label">Sezione:</span>
        <a href="utenti.php"><i class="la la-users" aria-hidden="true"></i> Utenti</a>
        <a class="active" href="ruoli_utenti.php"><i class="la la-user-tag" aria-hidden="true"></i> Ruoli utenti</a>
        <a href="permessi_ruoli.php"><i class="la la-key" aria-hidden="true"></i> Permessi ruoli</a>
    </div>

    <div class="hr-filter-toolbar">
        <div class="hr-filter-toolbar-main">
            <h2 style="margin-bottom:.35rem;">Ruoli utenti</h2>
            <p class="muted" style="margin:0;">Ogni utente eredita i permessi dal ruolo attivo assegnato.</p>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="filtroRapidoRuoliUtenti">Filtro rapido</label>
            <input
                type="search"
                id="filtroRapidoRuoliUtenti"
                placeholder="Cerca in tutte le colonne..."
                autocomplete="off"
                data-table-filter="tabellaRuoliUtenti"
            >
        </div>
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="post">
        <div class="table-wrap">
            <table id="tabellaRuoliUtenti">
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
                        $utenteAttivo = (int)$utente['attivo'] === 1;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$utente['username']) ?></td>
                            <td><?= htmlspecialchars($nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username']) ?></td>
                            <td>
                                <span class="status-badge user-badge <?= classeBadgeRuoloUtente($utenteAttivo) ?>">
                                    <?= $utenteAttivo ? 'Attivo' : 'Disattivo' ?>
                                </span>
                            </td>
                            <td>
                                <select class="role-select" name="<?= htmlspecialchars($chiave) ?>">
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
        </div>

        <div class="admin-save-actions">
            <button class="btn btn-primary" type="submit"><i class="la la-save" aria-hidden="true"></i> Salva ruoli utenti</button>
        </div>
    </form>

    <div class="links">
        <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla dashboard</a>
    </div>
</div>

<script>
(function () {
    function normalizzaTesto(valore) {
        return (valore || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var tableId = input.getAttribute('data-table-filter');
        var table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        input.addEventListener('input', function () {
            var filtro = normalizzaTesto(input.value);
            table.querySelectorAll('tbody tr').forEach(function (row) {
                var testo = normalizzaTesto(row.textContent);
                row.style.display = testo.indexOf(filtro) !== -1 ? '' : 'none';
            });
        });
    });
})();
</script>

<?php layoutFooter(); ?>
