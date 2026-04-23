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

    $tipiRelazione = $pdo->query("SELECT * FROM hr_tipi_relazione_organizzativa WHERE attivo = 1 AND codice IN ('RESPONSABILE_DIRETTO','RESPONSABILE_FUNZIONALE') ORDER BY descrizione")->fetchAll();

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
<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Relazioni organizzative</h1>
            <div class="meta">Qui registri il rapporto tra un utente e un altro utente. Esempio: Mario <strong>risponde funzionalmente a</strong> Paolo. La gerarchia si ferma al primo livello diretto.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php">Torna alla configurazione</a>
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
        <style>.relation-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#f3efff;color:#5f3dc4;border:1px solid #d9ccff;font-weight:700;margin-right:6px;} .group-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#eef8ff;color:#0b7285;border:1px solid #c5e8ef;font-weight:700;margin-right:6px;}</style>
        <div class="hr-admin-grid">
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
                <label for="id_utente_collegato">Altro utente</label>
                <select name="id_utente_collegato" id="id_utente_collegato" required>
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
                    <?php foreach ($tipiRelazione as $t): ?>
                        <option value="<?= (int)$t['id_tipo_relazione'] ?>"><?= h(descrizioneRelazioneBreve((string)$t['codice'], (string)$t['descrizione'])) ?></option>
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
            <div class="form-group hr-col-span-2">
                <label for="note">Note</label>
                <input type="text" name="note" id="note" maxlength="255">
            </div>
        </div>
        <div class="actions"><button type="submit">Salva relazione</button></div>
    </form>
    <?php endif; ?>
</div>

<div class="card card-wide">
    <h2>Relazioni registrate</h2>
    <div class="table-wrap">
        <table>
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
                    <td><span class="relation-icon">↕</span><?= h(descrizioneRelazioneBreve((string)$r['codice'] ?? '', (string)$r['tipo_relazione'])) ?></td>
                    <td><?= h((string)$r['utente_collegato']) ?></td>
                    <td><?= h((string)$r['data_inizio']) ?><?= $r['data_fine'] ? ' → ' . h((string)$r['data_fine']) : '' ?></td>
                    <td><?= h((string)$r['note']) ?></td>
                    <td><span class="status-badge <?= (int)$r['attiva'] === 1 ? 'status-ok' : 'status-neutral' ?>"><?= (int)$r['attiva'] === 1 ? 'Attiva' : 'Chiusa' ?></span></td>
                    <td>
                        <?php if ($puoScrivere && (int)$r['attiva'] === 1): ?>
                            <form method="post" action="relazioni_organizzative.php" onsubmit="return confirm('Chiudere questa relazione?');">
                                <input type="hidden" name="azione" value="chiudi_relazione">
                                <input type="hidden" name="id_relazione_organizzativa" value="<?= (int)$r['id_relazione_organizzativa'] ?>">
                                <button type="submit" class="btn-light">Chiudi</button>
                            </form>
                        <?php else: ?><span class="meta">-</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layoutFooter(); ?>
