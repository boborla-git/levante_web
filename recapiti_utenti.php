<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('recapiti_utenti');

$pdo = db();
$puoScrivere = haPermessoScrittura('recapiti_utenti');
$errore = '';
$messaggio = '';
$utenti = [];
$tipiRecapito = [];
$recapiti = [];
$totaleUtentiAttivi = 0;
$totaleRecapitiAttivi = 0;

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function boolLabel(int $value, string $label): string
{
    return $value === 1 ? '<span class="badge badge-success">' . h($label) . '</span>' : '<span class="muted">-</span>';
}

function nominativoUtente(array $u): string
{
    $nome = trim((string)($u['nominativo'] ?? ''));
    if ($nome !== '') {
        return $nome;
    }
    return (string)($u['username'] ?? '');
}

function hrRecapitoValido(string $codiceTipo, string $valore): bool
{
    if (strpos($codiceTipo, 'EMAIL_') === 0) {
        return filter_var($valore, FILTER_VALIDATE_EMAIL) !== false;
    }
    if (strpos($codiceTipo, 'CELLULARE_') === 0) {
        return preg_match('/^[0-9 +().\-]{6,30}$/', $valore) === 1;
    }
    return trim($valore) !== '';
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_recapito') {
            $idRecapito = (int)($_POST['id_recapito_utente'] ?? 0);
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $idTipo = (int)($_POST['id_tipo_recapito'] ?? 0);
            $valore = trim((string)($_POST['valore'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));
            $principale = isset($_POST['principale']) ? 1 : 0;
            $verificato = isset($_POST['verificato']) ? 1 : 0;
            $attivo = isset($_POST['attivo']) ? 1 : 0;

            if ($idUtente <= 0 || $idTipo <= 0 || $valore === '') {
                throw new RuntimeException('Utente, tipo recapito e valore sono obbligatori.');
            }

            $stmtTipo = $pdo->prepare('SELECT codice FROM hr_tipi_recapito WHERE id_tipo_recapito = :id AND attivo = 1');
            $stmtTipo->execute(['id' => $idTipo]);
            $codiceTipo = (string)($stmtTipo->fetchColumn() ?: '');

            if ($codiceTipo === '') {
                throw new RuntimeException('Tipo recapito non valido.');
            }
            if (!hrRecapitoValido($codiceTipo, $valore)) {
                throw new RuntimeException('Il valore del recapito non è valido per il tipo selezionato.');
            }

            $pdo->beginTransaction();

            if ($principale === 1) {
                $stmtReset = $pdo->prepare(
                    'UPDATE hr_recapiti_utenti
                     SET principale = 0
                     WHERE id_utente = :id_utente
                       AND id_tipo_recapito = :id_tipo_recapito
                       AND (:id_recapito_utente = 0 OR id_recapito_utente <> :id_recapito_utente)'
                );
                $stmtReset->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'id_recapito_utente' => $idRecapito,
                ]);
            }

            if ($idRecapito > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE hr_recapiti_utenti
                     SET id_utente = :id_utente,
                         id_tipo_recapito = :id_tipo_recapito,
                         valore = :valore,
                         principale = :principale,
                         verificato = :verificato,
                         attivo = :attivo,
                         note = :note
                     WHERE id_recapito_utente = :id_recapito_utente'
                );
                $stmt->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'valore' => $valore,
                    'principale' => $principale,
                    'verificato' => $verificato,
                    'attivo' => $attivo,
                    'note' => $note !== '' ? $note : null,
                    'id_recapito_utente' => $idRecapito,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO hr_recapiti_utenti
                     (id_utente, id_tipo_recapito, valore, principale, verificato, attivo, note)
                     VALUES (:id_utente, :id_tipo_recapito, :valore, :principale, :verificato, :attivo, :note)'
                );
                $stmt->execute([
                    'id_utente' => $idUtente,
                    'id_tipo_recapito' => $idTipo,
                    'valore' => $valore,
                    'principale' => $principale,
                    'verificato' => $verificato,
                    'attivo' => $attivo,
                    'note' => $note !== '' ? $note : null,
                ]);
            }

            $pdo->commit();
            header('Location: recapiti_utenti.php?ok=1');
            exit;
        }

        if ($azione === 'disattiva_recapito') {
            $idRecapito = (int)($_POST['id_recapito_utente'] ?? 0);
            if ($idRecapito <= 0) {
                throw new RuntimeException('Recapito non valido.');
            }
            $stmt = $pdo->prepare('UPDATE hr_recapiti_utenti SET attivo = 0, principale = 0 WHERE id_recapito_utente = :id');
            $stmt->execute(['id' => $idRecapito]);
            header('Location: recapiti_utenti.php?disattivato=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Recapito salvato correttamente.';
    } elseif (isset($_GET['disattivato'])) {
        $messaggio = 'Recapito disattivato correttamente.';
    }

    $totaleUtentiAttivi = (int)$pdo->query('SELECT COUNT(*) FROM aut_utenti WHERE attivo = 1')->fetchColumn();
    $totaleRecapitiAttivi = (int)$pdo->query('SELECT COUNT(*) FROM hr_recapiti_utenti WHERE attivo = 1')->fetchColumn();

    $utenti = $pdo->query(
        "SELECT id_utente,
                username,
                nome,
                cognome,
                email,
                telefono,
                TRIM(CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,''))) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY
             CASE WHEN TRIM(COALESCE(cognome,'')) = '' THEN 1 ELSE 0 END,
             cognome,
             nome,
             username"
    )->fetchAll(PDO::FETCH_ASSOC);

    $tipiRecapito = $pdo->query('SELECT * FROM hr_tipi_recapito WHERE attivo = 1 ORDER BY id_tipo_recapito')->fetchAll(PDO::FETCH_ASSOC);

    $recapiti = $pdo->query(
        "SELECT ru.*,
                tr.codice AS tipo_codice,
                tr.descrizione AS tipo_descrizione,
                u.username,
                u.email AS email_login,
                u.telefono AS telefono_login,
                TRIM(CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''))) AS nominativo
         FROM hr_recapiti_utenti ru
         INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito
         INNER JOIN aut_utenti u ON u.id_utente = ru.id_utente
         ORDER BY
             CASE WHEN TRIM(COALESCE(u.cognome,'')) = '' THEN 1 ELSE 0 END,
             u.cognome,
             u.nome,
             u.username,
             tr.id_tipo_recapito,
             ru.principale DESC,
             ru.attivo DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

layoutHeader('Recapiti utenti');
?>

<style>
.hr-recapiti-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(280px, 420px);
    gap: 16px;
    align-items: end;
    margin-bottom: 12px;
}
.hr-recapiti-search-group {
    margin-bottom: 0;
}
.hr-recapiti-search-group input {
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
.hr-recapiti-search-group input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.16);
}
.hr-recapito-form-row {
    display: grid;
    grid-template-columns: minmax(300px, 2fr) minmax(170px, 1fr) minmax(250px, 1.35fr) minmax(250px, 1.35fr) auto auto;
    gap: 14px;
    align-items: start;
}
.hr-recapito-form-row .form-group {
    margin-bottom: 0;
}
.hr-recapito-form-row label {
    display: block;
    min-height: 20px;
    line-height: 20px;
    margin-bottom: 6px;
}
.hr-recapito-form-row input[type="text"],
.hr-recapito-form-row select {
    width: 100%;
    min-height: 40px;
}
.hr-recapito-note-row {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) auto auto;
    gap: 16px;
    align-items: end;
    margin-top: 16px;
}
.hr-recapito-options {
    min-width: 220px;
}
.hr-recapito-options-list {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: center;
    min-height: 40px;
}
.hr-recapito-actions {
    display: flex;
    justify-content: flex-end;
    align-items: flex-start;
    min-width: 145px;
    white-space: nowrap;
    padding-top: 26px;
}
.hr-recapiti-table td,
.hr-recapiti-table th {
    vertical-align: middle;
}
.hr-user-hint {
    color: #64748b;
    font-size: 12px;
    margin-top: 4px;
}
@media (max-width: 1100px) {
    .hr-recapiti-toolbar,
    .hr-recapito-form-row {
        grid-template-columns: 1fr;
    }
    .hr-recapito-options-list {
        flex-wrap: wrap;
        align-items: flex-start;
    }
    .hr-recapito-actions {
        justify-content: flex-start;
        min-width: 0;
    }
}
</style>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Recapiti utenti</h1>
            <div class="meta">Gestisci email e recapiti usati dal modulo HR per notifiche, autorizzazioni e comunicazioni operative.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?><div class="errore"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="ok"><?= h($messaggio) ?></div><?php endif; ?>

<div class="summary-line">
    <strong><?= (int)$totaleUtentiAttivi ?></strong> utenti attivi
    <strong><?= (int)count($utenti) ?></strong> utenti caricati nella tendina
    <strong><?= (int)$totaleRecapitiAttivi ?></strong> recapiti attivi
    <strong><?= (int)count($recapiti) ?></strong> recapiti totali elencati
</div>

<div class="card card-form">
    <h2>Nuovo recapito</h2>
    <?php if (!$puoScrivere): ?>
        <div class="info-box">Il tuo profilo può consultare ma non modificare i recapiti.</div>
    <?php else: ?>
        <form method="post" action="recapiti_utenti.php">
            <input type="hidden" name="azione" value="salva_recapito">
            <input type="hidden" name="id_recapito_utente" value="0">

            <div class="hr-recapito-form-row">
                <div class="form-group">
                    <label for="id_utente">Utente</label>
                    <select name="id_utente" id="id_utente" required>
                        <option value="">Seleziona...</option>
                        <?php foreach ($utenti as $u): ?>
                            <?php $nome = nominativoUtente($u); ?>
                            <option value="<?= (int)$u['id_utente'] ?>">
                                <?= h($nome) ?> (<?= h((string)$u['username']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="hr-user-hint">Sono mostrati tutti gli utenti attivi presenti in aut_utenti.</div>
                </div>

                <div class="form-group">
                    <label for="id_tipo_recapito">Tipo</label>
                    <select name="id_tipo_recapito" id="id_tipo_recapito" required>
                        <option value="">Seleziona...</option>
                        <?php foreach ($tipiRecapito as $tipo): ?>
                            <option value="<?= (int)$tipo['id_tipo_recapito'] ?>"><?= h((string)$tipo['descrizione']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="valore">Valore</label>
                    <input type="text" name="valore" id="valore" maxlength="255" required>
                </div>

                <div class="form-group">
                    <label for="note">Note</label>
                    <input type="text" name="note" id="note" maxlength="255">
                </div>

                <div class="form-group hr-recapito-options">
                    <label>Opzioni</label>
                    <div class="hr-recapito-options-list">
                        <label><input type="checkbox" name="principale" value="1" checked> principale</label>
                        <label><input type="checkbox" name="verificato" value="1" checked> verificato</label>
                        <label><input type="checkbox" name="attivo" value="1" checked> attivo</label>
                    </div>
                </div>

                <div class="form-group hr-recapito-actions">
                    <button type="submit" class="btn btn-primary"><i class="la la-save" aria-hidden="true"></i> Salva recapito</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="hr-recapiti-toolbar">
        <div>
            <h2>Recapiti registrati</h2>
            <div class="meta">Elenco dei recapiti già presenti nel modulo HR.</div>
        </div>
        <div class="form-group hr-recapiti-search-group">
            <label for="recapitiSearch">Filtro rapido</label>
            <input type="search" id="recapitiSearch" placeholder="Cerca in tutte le colonne...">
        </div>
    </div>

    <table class="table hr-recapiti-table" id="recapitiTable">
        <thead>
            <tr>
                <th>Utente</th>
                <th>Tipo</th>
                <th>Valore</th>
                <th>Opzioni</th>
                <th>Note</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$recapiti): ?>
                <tr><td colspan="6">Nessun recapito registrato.</td></tr>
            <?php endif; ?>
            <?php foreach ($recapiti as $r): ?>
                <?php $nome = nominativoUtente($r); ?>
                <tr>
                    <td>
                        <strong><?= h($nome) ?></strong><br>
                        <span class="muted"><?= h((string)$r['username']) ?></span>
                    </td>
                    <td><?= h((string)$r['tipo_descrizione']) ?></td>
                    <td><?= h((string)$r['valore']) ?></td>
                    <td>
                        <?= boolLabel((int)$r['principale'], 'principale') ?>
                        <?= boolLabel((int)$r['verificato'], 'verificato') ?>
                        <?= boolLabel((int)$r['attivo'], 'attivo') ?>
                    </td>
                    <td><?= h((string)($r['note'] ?? '')) ?></td>
                    <td>
                        <?php if ($puoScrivere): ?>
                            <details>
                                <summary class="btn btn-light">Modifica</summary>
                                <form method="post" action="recapiti_utenti.php" class="mt-2">
                                    <input type="hidden" name="azione" value="salva_recapito">
                                    <input type="hidden" name="id_recapito_utente" value="<?= (int)$r['id_recapito_utente'] ?>">
                                    <input type="hidden" name="id_utente" value="<?= (int)$r['id_utente'] ?>">
                                    <label>Tipo</label>
                                    <select name="id_tipo_recapito" required>
                                        <?php foreach ($tipiRecapito as $tipo): ?>
                                            <option value="<?= (int)$tipo['id_tipo_recapito'] ?>" <?= (int)$tipo['id_tipo_recapito'] === (int)$r['id_tipo_recapito'] ? 'selected' : '' ?>><?= h((string)$tipo['descrizione']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label>Valore</label>
                                    <input type="text" name="valore" value="<?= h((string)$r['valore']) ?>" maxlength="255" required>
                                    <label>Note</label>
                                    <input type="text" name="note" value="<?= h((string)($r['note'] ?? '')) ?>" maxlength="255">
                                    <label><input type="checkbox" name="principale" value="1" <?= (int)$r['principale'] === 1 ? 'checked' : '' ?>> principale</label>
                                    <label><input type="checkbox" name="verificato" value="1" <?= (int)$r['verificato'] === 1 ? 'checked' : '' ?>> verificato</label>
                                    <label><input type="checkbox" name="attivo" value="1" <?= (int)$r['attivo'] === 1 ? 'checked' : '' ?>> attivo</label>
                                    <button type="submit" class="btn btn-primary">Salva</button>
                                </form>
                            </details>

                            <?php if ((int)$r['attivo'] === 1): ?>
                                <form method="post" action="recapiti_utenti.php" style="margin-top:8px" onsubmit="return confirm('Confermi la disattivazione del recapito?');">
                                    <input type="hidden" name="azione" value="disattiva_recapito">
                                    <input type="hidden" name="id_recapito_utente" value="<?= (int)$r['id_recapito_utente'] ?>">
                                    <button type="submit" class="btn btn-danger">Disattiva</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
(function () {
    const input = document.getElementById('recapitiSearch');
    const table = document.getElementById('recapitiTable');
    if (!input || !table) return;
    input.addEventListener('input', function () {
        const needle = input.value.toLowerCase().trim();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(needle) ? '' : 'none';
        });
    });
})();
</script>

<?php layoutFooter(); ?>
