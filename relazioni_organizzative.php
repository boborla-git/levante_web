<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$errore = '';
$messaggio = '';
$utenti = [];
$tipiRelazione = [];
$relazioni = [];
$relazioniAttive = [];
$collaboratoriPerResponsabile = [];
$relazioniPerUtente = [];
$riepilogo = [
    'relazioni_totali' => 0,
    'relazioni_attive' => 0,
    'relazioni_chiuse' => 0,
    'responsabili' => 0,
    'collaboratori' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function descrizioneRelazioneBreve(string $codice, string $fallback): string
{
    if ($codice === 'RESPONSABILE_DIRETTO' || $codice === 'RESPONSABILE_FUNZIONALE') {
        return 'risponde funzionalmente a';
    }
    if ($codice === 'REFERENTE_HR') {
        return 'referente HR';
    }
    return $fallback;
}

function hrRelazioneNomeUtente(array $r, string $prefix): string
{
    $nome = trim((string)($r[$prefix . '_nome'] ?? ''));
    $cognome = trim((string)($r[$prefix . '_cognome'] ?? ''));
    $username = trim((string)($r[$prefix . '_username'] ?? ''));
    $label = trim($nome . ' ' . $cognome);
    return $label !== '' ? $label : $username;
}

function hrRelazioneUsername(array $r, string $prefix): string
{
    return trim((string)($r[$prefix . '_username'] ?? ''));
}

function hrRelazioneTestBadge(string $username): string
{
    if (strpos($username, 'test_') === 0) {
        return '<span class="hr-org-chip hr-org-chip-test">Test</span>';
    }
    return '<span class="hr-org-chip hr-org-chip-real">Reale</span>';
}

function hrRelazionePeriodo(array $r): string
{
    $inizio = trim((string)($r['data_inizio'] ?? ''));
    $fine = trim((string)($r['data_fine'] ?? ''));
    if ($inizio === '' && $fine === '') {
        return 'Periodo non indicato';
    }
    return $fine !== '' ? $inizio . ' - ' . $fine : 'Dal ' . $inizio;
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

    $relazioni = $pdo->query(
        "SELECT ro.*, tr.codice, tr.descrizione AS tipo_relazione,
                u.username AS utente_username, u.nome AS utente_nome, u.cognome AS utente_cognome,
                uc.username AS collegato_username, uc.nome AS collegato_nome, uc.cognome AS collegato_cognome,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,''), ' (', u.username, ')') AS utente,
                CONCAT(COALESCE(uc.nome,''), ' ', COALESCE(uc.cognome,''), ' (', uc.username, ')') AS utente_collegato
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tr ON tr.id_tipo_relazione = ro.id_tipo_relazione
         INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente
         INNER JOIN aut_utenti uc ON uc.id_utente = ro.id_utente_collegato
         ORDER BY ro.attiva DESC, uc.cognome, uc.nome, u.cognome, u.nome, ro.data_inizio DESC"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($relazioni as $r) {
        $riepilogo['relazioni_totali']++;
        if ((int)$r['attiva'] === 1) {
            $riepilogo['relazioni_attive']++;
            $relazioniAttive[] = $r;
            $idResponsabile = (int)$r['id_utente_collegato'];
            $idCollaboratore = (int)$r['id_utente'];
            $collaboratoriPerResponsabile[$idResponsabile]['responsabile'] = [
                'nome' => hrRelazioneNomeUtente($r, 'collegato'),
                'username' => hrRelazioneUsername($r, 'collegato'),
            ];
            $collaboratoriPerResponsabile[$idResponsabile]['collaboratori'][$idCollaboratore] = $r;
            $relazioniPerUtente[$idCollaboratore][] = $r;
        } else {
            $riepilogo['relazioni_chiuse']++;
        }
    }

    $riepilogo['responsabili'] = count($collaboratoriPerResponsabile);
    $collaboratoriUnici = [];
    foreach ($relazioniAttive as $r) {
        $collaboratoriUnici[(int)$r['id_utente']] = true;
    }
    $riepilogo['collaboratori'] = count($collaboratoriUnici);

    uasort($collaboratoriPerResponsabile, static function (array $a, array $b): int {
        return strcmp((string)$a['responsabile']['nome'], (string)$b['responsabile']['nome']);
    });
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Relazioni organizzative');
?>
<link rel="stylesheet" href="/assets/hr.css">


<div class="card card-compact hr-org-hero">
    <div class="section-head">
        <div>
            <h1>Relazioni organizzative</h1>
            <div class="meta">Mappa leggibile dei rapporti diretti: chi risponde funzionalmente a chi. La visibilita gerarchica resta al primo livello diretto.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
        </div>
    </div>
</div>

<section class="hr-org-summary">
    <span><strong><?= (int)$riepilogo['relazioni_attive'] ?></strong> relazioni attive</span>
    <span><strong><?= (int)$riepilogo['responsabili'] ?></strong> responsabili / referenti</span>
    <span><strong><?= (int)$riepilogo['collaboratori'] ?></strong> collaboratori collegati</span>
    <span><strong><?= (int)$riepilogo['relazioni_chiuse'] ?></strong> relazioni chiuse</span>
</section>

<?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>

<div class="card card-form">
    <h2>Nuova relazione</h2>
    <?php if (!$puoScrivere): ?>
        <div class="info-box">Il tuo profilo puo consultare ma non modificare le relazioni.</div>
    <?php else: ?>
    <form method="post" action="relazioni_organizzative.php">
        <input type="hidden" name="azione" value="nuova_relazione">
        <div class="info-box">Compila la frase organizzativa: <strong>Utente</strong> → <strong>risponde funzionalmente a</strong> → <strong>responsabile / referente</strong>.</div>
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
                <label for="id_utente_collegato">Responsabile / referente</label>
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
    <div class="hr-org-toolbar">
        <div>
            <h2>Mappa responsabili / referenti</h2>
            <div class="meta">Vista per responsabile: mostra solo i collegamenti attivi e diretti.</div>
        </div>
        <div class="form-group hr-org-search">
            <label for="orgSearch">Filtro rapido</label>
            <input type="search" id="orgSearch" placeholder="Cerca responsabile, collaboratore, note...">
        </div>
    </div>

    <?php if (count($collaboratoriPerResponsabile) === 0): ?>
        <div class="hr-org-empty">Nessuna relazione attiva presente. Inserisci una relazione per costruire la mappa organizzativa.</div>
    <?php else: ?>
        <div class="hr-org-grid" id="orgCards">
            <?php foreach ($collaboratoriPerResponsabile as $idResponsabile => $gruppo): ?>
                <?php
                $responsabile = $gruppo['responsabile'];
                $collaboratori = $gruppo['collaboratori'] ?? [];
                $searchText = strtolower((string)$responsabile['nome'] . ' ' . (string)$responsabile['username']);
                foreach ($collaboratori as $collab) {
                    $searchText .= ' ' . strtolower(hrRelazioneNomeUtente($collab, 'utente') . ' ' . hrRelazioneUsername($collab, 'utente') . ' ' . (string)($collab['note'] ?? ''));
                }
                ?>
                <article class="hr-org-card" data-org-card data-search="<?= h($searchText) ?>">
                    <div class="hr-org-card-head">
                        <div>
                            <h3 class="hr-org-card-title"><?= h((string)$responsabile['nome']) ?></h3>
                            <div class="hr-org-card-user">@<?= h((string)$responsabile['username']) ?></div>
                            <div class="hr-org-chip-row"><?= hrRelazioneTestBadge((string)$responsabile['username']) ?></div>
                        </div>
                        <?= renderHrStatusBadge('ATTIVO', 'Attivo') ?>
                    </div>
                    <div class="hr-org-card-body">
                        <div class="hr-org-small-title">Collaboratori diretti</div>
                        <?php foreach ($collaboratori as $r): ?>
                            <div class="hr-org-relation-line">
                                <span class="hr-org-relation-icon"><i class="la la-user-check" aria-hidden="true"></i></span>
                                <div>
                                    <div class="hr-org-person"><?= h(hrRelazioneNomeUtente($r, 'utente')) ?></div>
                                    <div class="hr-org-meta">@<?= h(hrRelazioneUsername($r, 'utente')) ?> · <?= h(descrizioneRelazioneBreve((string)$r['codice'], (string)$r['tipo_relazione'])) ?></div>
                                    <div class="hr-org-chip-row">
                                        <?= hrRelazioneTestBadge(hrRelazioneUsername($r, 'utente')) ?>
                                        <span class="hr-org-chip hr-org-chip-muted"><?= h(hrRelazionePeriodo($r)) ?></span>
                                    </div>
                                    <?php if (trim((string)($r['note'] ?? '')) !== ''): ?>
                                        <div class="hr-org-meta">Note: <?= h((string)$r['note']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card card-wide hr-org-history">
    <div class="hr-org-toolbar">
        <div>
            <h2>Relazioni registrate</h2>
            <div class="meta">Archivio completo: include relazioni attive e chiuse.</div>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="relazioniSearch">Filtro rapido</label>
            <input type="search" id="relazioniSearch" data-table-filter="relazioniTable" placeholder="Cerca in tutte le colonne...">
        </div>
    </div>
    <div class="table-wrap">
        <table id="relazioniTable">
            <thead>
                <tr>
                    <th>Utente</th>
                    <th>Relazione</th>
                    <th>Responsabile / referente</th>
                    <th>Periodo</th>
                    <th>Note</th>
                    <th>Stato</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($relazioni as $r): ?>
                <tr>
                    <td><strong><?= h(hrRelazioneNomeUtente($r, 'utente')) ?></strong><br><span class="meta">@<?= h(hrRelazioneUsername($r, 'utente')) ?></span></td>
                    <td><span class="relation-icon"><i class="la la-level-up-alt" aria-hidden="true"></i></span><?= h(descrizioneRelazioneBreve((string)$r['codice'], (string)$r['tipo_relazione'])) ?></td>
                    <td><strong><?= h(hrRelazioneNomeUtente($r, 'collegato')) ?></strong><br><span class="meta">@<?= h(hrRelazioneUsername($r, 'collegato')) ?></span></td>
                    <td><?= h(hrRelazionePeriodo($r)) ?></td>
                    <td><?= h((string)$r['note']) ?></td>
                    <td><?= renderHrStatusBadge((int)$r['attiva'] === 1 ? 'ATTIVA' : 'CHIUSA', (int)$r['attiva'] === 1 ? 'Attiva' : 'Chiusa') ?></td>
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
    var search = document.getElementById('orgSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-org-card]'));
    if (!search || cards.length === 0) {
        return;
    }

    search.addEventListener('input', function () {
        var value = String(search.value || '').trim().toLowerCase();
        cards.forEach(function (card) {
            var text = String(card.getAttribute('data-search') || '').toLowerCase();
            card.style.display = value === '' || text.indexOf(value) !== -1 ? '' : 'none';
        });
    });
}());
</script>

<?php layoutFooter(); ?>
