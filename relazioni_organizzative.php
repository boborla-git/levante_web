<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$errore = '';
$messaggio = '';
$utenti = [];
$tipiRelazione = [];
$relazioniAttive = [];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}


function descrizioneRelazioneBreve(string $codice, string $fallback): string
{
    if ($codice === 'RESPONSABILE_DIRETTO' || $codice === 'RESPONSABILE_FUNZIONALE') { return 'risponde funzionalmente a'; }
    if ($codice === 'REFERENTE_HR') { return 'referente HR'; }
    return $fallback;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'nuova_relazione') {
            $idUtente = (int)($_POST['id_utente'] ?? 0);
            $idCollegato = (int)($_POST['id_utente_collegato'] ?? 0);
            $idTipo = (int)($_POST['id_tipo_relazione'] ?? 0);
            $dataInizio = trim((string)($_POST['data_inizio'] ?? ''));
            $dataFine = trim((string)($_POST['data_fine'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($idUtente <= 0 || $idCollegato <= 0 || $idTipo <= 0 || $dataInizio === '') {
                throw new RuntimeException('Compila tutti i campi obbligatori.');
            }
            if ($idUtente === $idCollegato) {
                throw new RuntimeException('Utente e utente collegato non possono coincidere.');
            }
            if ($dataFine !== '' && $dataFine < $dataInizio) {
                throw new RuntimeException('La data fine non può precedere la data inizio.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO hr_relazioni_organizzative
                (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
                VALUES (:id_utente, :id_utente_collegato, :id_tipo_relazione, :data_inizio, :data_fine, 1, :note)'
            );
            $stmt->execute([
                'id_utente' => $idUtente,
                'id_utente_collegato' => $idCollegato,
                'id_tipo_relazione' => $idTipo,
                'data_inizio' => $dataInizio,
                'data_fine' => $dataFine !== '' ? $dataFine : null,
                'note' => $note !== '' ? $note : null,
            ]);

            header('Location: relazioni_organizzative.php?ok=1');
            exit;
        }

        if ($azione === 'chiudi_relazione') {
            $idRelazione = (int)($_POST['id_relazione_organizzativa'] ?? 0);
            if ($idRelazione <= 0) {
                throw new RuntimeException('Relazione non valida.');
            }
            $stmt = $pdo->prepare('UPDATE hr_relazioni_organizzative SET attiva = 0, data_fine = COALESCE(data_fine, CURDATE()) WHERE id_relazione_organizzativa = :id');
            $stmt->execute(['id' => $idRelazione]);

            header('Location: relazioni_organizzative.php?chiusa=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Relazione salvata correttamente.';
    } elseif (isset($_GET['chiusa'])) {
        $messaggio = 'Relazione chiusa correttamente.';
    }

    $utenti = $pdo->query(
        "SELECT id_utente, username, CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,'')) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY nominativo, username"
    )->fetchAll();

    $tipiRelazione = $pdo->query("SELECT * FROM hr_tipi_relazione_organizzativa WHERE attivo = 1 AND codice IN ('RESPONSABILE_FUNZIONALE','RESPONSABILE_DIRETTO') ORDER BY CASE WHEN codice = 'RESPONSABILE_FUNZIONALE' THEN 0 ELSE 1 END, descrizione")->fetchAll();

    $relazioniAttive = $pdo->query(
        "SELECT ro.*, tr.codice, tr.descrizione AS tipo_relazione,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''), ' (', u.username, ')') AS utente,
                CONCAT(COALESCE(uc.nome,''), ' ', COALESCE(uc.cognome,''), ' (', uc.username, ')') AS utente_collegato
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tr ON tr.id_tipo_relazione = ro.id_tipo_relazione
         INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente
         INNER JOIN aut_utenti uc ON uc.id_utente = ro.id_utente_collegato
         ORDER BY ro.attiva DESC, ro.data_inizio DESC, ro.id_relazione_organizzativa DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Relazioni organizzative');
?>

<style>
.hr-filter-toolbar {
    display: grid;
    grid-template-columns: minmax(220px, 1fr) minmax(280px, 420px);
    gap: 16px;
    align-items: end;
    margin-bottom: 12px;
}
.hr-filter-search-group {
    margin-bottom: 0;
}
.hr-filter-search-group input {
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
.hr-filter-search-group input:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.16);
}
.hr-wide-form-row {
    display: grid;
    gap: 14px;
    align-items: end;
}
.hr-wide-form-row .form-group {
    margin-bottom: 0;
}
.hr-wide-form-row label {
    display: block;
    min-height: 20px;
    line-height: 20px;
    margin-bottom: 6px;
}
.hr-wide-form-row input[type="text"],
.hr-wide-form-row input[type="date"],
.hr-wide-form-row select {
    width: 100%;
    min-height: 40px;
}
.hr-relazioni-form-row {
    grid-template-columns: minmax(220px, 1.2fr) minmax(220px, 1.2fr) minmax(180px, 1fr) 150px 150px minmax(220px, 1fr);
}
.hr-gruppo-form-row {
    grid-template-columns: minmax(140px, 0.7fr) minmax(240px, 1.2fr) minmax(320px, 2fr) auto;
}
.hr-appartenenza-form-row {
    grid-template-columns: minmax(220px, 1.2fr) minmax(240px, 1.2fr) minmax(180px, 1fr) 150px 150px auto;
}
.hr-form-actions-inline {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    min-width: 130px;
    padding-top: 26px;
}
@media (max-width: 1100px) {
    .hr-filter-toolbar,
    .hr-wide-form-row {
        grid-template-columns: 1fr;
    }
    .hr-form-actions-inline {
        justify-content: flex-start;
        min-width: 0;
        padding-top: 0;
    }
}
@media (max-width: 700px) {
    .section-head,
    .section-head-actions {
        align-items: stretch;
    }
    .section-head-actions .btn,
    .actions button,
    .hr-form-actions-inline button {
        width: 100%;
        justify-content: center;
    }
    .hr-filter-toolbar {
        gap: 10px;
    }
}
</style>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Relazioni organizzative</h1>
            <div class="meta">Qui registri il rapporto tra un utente e un altro utente. Esempio: Mario <strong>risponde funzionalmente a</strong> Paolo. La gerarchia si ferma al primo livello diretto.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?><div class="errore"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="ok"><?= h($messaggio) ?></div><?php endif; ?>

<div class="card card-form">
    <h2>Nuova relazione</h2>
    <?php if (!$puoScrivere): ?>
        <div class="info-box">Il tuo profilo può consultare ma non modificare le relazioni.</div>
    <?php else: ?>
    <form method="post" action="relazioni_organizzative.php">
        <input type="hidden" name="azione" value="nuova_relazione">
        <div class="info-box">Compila la relazione come una frase: <strong>Utente</strong> → <strong>relazione</strong> → <strong>altro utente</strong>.</div>
        <div class="hr-wide-form-row hr-relazioni-form-row">
            <div class="form-group">
                <label for="id_utente">Utente</label>
                <select name="id_utente" id="id_utente" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($utenti as $u): ?>
                        <option value="<?= (int)$u['id_utente'] ?>"><?= h(trim((string)$u['nominativo']) !== '' ? (string)$u['nominativo'] : (string)$u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="id_tipo_relazione">Relazione</label>
                <select name="id_tipo_relazione" id="id_tipo_relazione" required>
                    <option value="">Seleziona...</option>
                    <?php
                    $relazioniViste = [];
                    foreach ($tipiRelazione as $t):
                        $descrizioneOpzione = descrizioneRelazioneBreve((string)$t['codice'], (string)$t['descrizione']);
                        if (isset($relazioniViste[$descrizioneOpzione])) {
                            continue;
                        }
                        $relazioniViste[$descrizioneOpzione] = true;
                    ?>
                        <option value="<?= (int)$t['id_tipo_relazione'] ?>"><?= h($descrizioneOpzione) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="id_utente_collegato">Altro utente</label>
                <select name="id_utente_collegato" id="id_utente_collegato" required>
                    <option value="">Seleziona...</option>
                    <?php foreach ($utenti as $u): ?>
                        <option value="<?= (int)$u['id_utente'] ?>"><?= h(trim((string)$u['nominativo']) !== '' ? (string)$u['nominativo'] : (string)$u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="data_inizio">Data inizio</label>
                <input type="date" name="data_inizio" id="data_inizio" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
                <label for="data_fine">Data fine</label>
                <input type="date" name="data_fine" id="data_fine">
            </div>
            <div class="form-group">
                <label for="note">Note</label>
                <input type="text" name="note" id="note" maxlength="255">
            </div>
        </div>
        <div class="actions"><button type="submit">Salva relazione</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card card-wide">
    <div class="hr-filter-toolbar">
        <div>
            <h2>Relazioni registrate</h2>
            <div class="meta">Filtra rapidamente per utente, relazione, periodo, stato o note.</div>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="relazioniSearch">Filtro rapido</label>
            <input type="search" id="relazioniSearch" placeholder="Cerca in tutte le colonne...">
        </div>
    </div>
    <div class="table-wrap">
        <table id="relazioniTable">
            <thead>
                <tr>
                    <th>Utente</th>
                    <th>Relazione</th>
                    <th>Altro utente</th>
                    <th>Periodo</th>
                    <th>Note</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($relazioniAttive as $r): ?>
                <tr>
                    <td><?= h((string)$r['utente']) ?></td>
                    <td><span class="relation-icon"><i class="la la-level-up-alt" aria-hidden="true"></i></span><?= h(descrizioneRelazioneBreve((string)$r['codice'] ?? '', (string)$r['tipo_relazione'])) ?></td>
                    <td><?= h((string)$r['utente_collegato']) ?></td>
                    <td><?= h((string)$r['data_inizio']) ?><?= $r['data_fine'] ? ' → ' . h((string)$r['data_fine']) : '' ?></td>
                    <td><?= h((string)$r['note']) ?></td>
                    <td><span class="status-badge <?= (int)$r['attiva'] === 1 ? 'status-ok' : 'status-neutral' ?>"><?= (int)$r['attiva'] === 1 ? 'Attiva' : 'Chiusa' ?></span></td>
                    <td>
                        <?php if ($puoScrivere && (int)$r['attiva'] === 1): ?>
                            <form method="post" action="relazioni_organizzative.php" onsubmit="return confirm('Chiudere questa relazione?');">
                                <input type="hidden" name="azione" value="chiudi_relazione">
                                <input type="hidden" name="id_relazione_organizzativa" value="<?= (int)$r['id_relazione_organizzativa'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="la la-times" aria-hidden="true"></i> Chiudi</button>
                            </form>
                        <?php else: ?><span class="meta">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
(function () {
    const input = document.getElementById('relazioniSearch');
    const table = document.getElementById('relazioniTable');
    if (!input || !table) return;
    input.addEventListener('input', function () {
        const term = this.value.trim().toLowerCase();
        table.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(term) ? '' : 'none';
        });
    });
})();
</script>
<?php layoutFooter(); ?>
