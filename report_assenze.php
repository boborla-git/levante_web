<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('approvazioni_assenze');

$pdo = db();
$idUtenteLoggato = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoConfigurare = haPermessoLettura('configurazione_assenze');

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function reportAssenzePeriodo(array $riga): string
{
    $periodo = (string)($riga['data_da'] ?? '');
    if ((string)($riga['data_a'] ?? '') !== '' && (string)$riga['data_a'] !== (string)$riga['data_da']) {
        $periodo .= ' - ' . (string)$riga['data_a'];
    }
    if ((string)($riga['tipo_periodo'] ?? '') === 'ORE' && (string)($riga['ora_da'] ?? '') !== '' && (string)($riga['ora_a'] ?? '') !== '') {
        $periodo .= ' ' . (string)$riga['ora_da'] . '-' . (string)$riga['ora_a'];
    }
    return $periodo;
}

$filtroStato = trim((string)($_GET['stato'] ?? ''));
$filtroTipologia = trim((string)($_GET['tipologia'] ?? ''));
$filtroDataDa = trim((string)($_GET['data_da'] ?? ''));
$filtroDataA = trim((string)($_GET['data_a'] ?? ''));
$filtroUtente = trim((string)($_GET['utente'] ?? ''));
$exportExcel = (string)($_GET['export'] ?? '') === 'excel';

$where = [];
$params = [];

if (!$puoConfigurare) {
    $where[] = 'a.id_approvatore_assegnato = :id_approvatore';
    $params['id_approvatore'] = $idUtenteLoggato;
}
if ($filtroStato !== '') {
    $where[] = 'sr.codice = :stato';
    $params['stato'] = $filtroStato;
}
if ($filtroTipologia !== '') {
    $where[] = 'te.codice = :tipologia';
    $params['tipologia'] = $filtroTipologia;
}
if ($filtroDataDa !== '') {
    $where[] = 'p.data_a >= :data_da';
    $params['data_da'] = $filtroDataDa;
}
if ($filtroDataA !== '') {
    $where[] = 'p.data_da <= :data_a';
    $params['data_a'] = $filtroDataA;
}
if ($filtroUtente !== '') {
    $where[] = '(u.username LIKE :utente OR u.nome LIKE :utente OR u.cognome LIKE :utente)';
    $params['utente'] = '%' . $filtroUtente . '%';
}
$whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT
            r.codice_richiesta,
            sr.descrizione AS stato,
            te.descrizione AS tipologia,
            u.username,
            CONCAT(TRIM(COALESCE(u.nome, '')), CASE WHEN TRIM(COALESCE(u.cognome, '')) <> '' THEN CONCAT(' ', TRIM(u.cognome)) ELSE '' END) AS richiedente,
            DATE_FORMAT(p.data_da, '%d/%m/%Y') AS data_da,
            DATE_FORMAT(p.data_a, '%d/%m/%Y') AS data_a,
            TIME_FORMAT(p.ora_da, '%H:%i') AS ora_da,
            TIME_FORMAT(p.ora_a, '%H:%i') AS ora_a,
            p.tipo_periodo,
            r.oggetto,
            r.note_richiedente,
            DATE_FORMAT(r.data_creazione, '%d/%m/%Y %H:%i') AS data_creazione
        FROM hr_richieste r
        INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
        INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
        INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
        LEFT JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta AND p.ordinamento = 1
        LEFT JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
        {$whereSql}
        GROUP BY r.id_richiesta, p.id_richiesta_periodo
        ORDER BY p.data_da DESC, r.data_creazione DESC";

$righe = [];
$erroreReport = '';
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $erroreReport = 'Impossibile leggere i dati del report assenze.';
}

if ($exportExcel && $erroreReport === '') {
    $nomeFile = 'HR_ReportAssenze_' . date('Y-m-d_H-i-s') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo "\xEF\xBB\xBF";
    ?>
    <table border="1">
        <thead>
        <tr>
            <th>Codice</th>
            <th>Stato</th>
            <th>Tipologia</th>
            <th>Dipendente</th>
            <th>Username</th>
            <th>Periodo</th>
            <th>Creata il</th>
            <th>Oggetto</th>
            <th>Note</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($righe as $riga): ?>
            <tr>
                <td><?= h((string)$riga['codice_richiesta']) ?></td>
                <td><?= h((string)$riga['stato']) ?></td>
                <td><?= h((string)$riga['tipologia']) ?></td>
                <td><?= h(trim((string)$riga['richiedente']) !== '' ? (string)$riga['richiedente'] : (string)$riga['username']) ?></td>
                <td><?= h((string)$riga['username']) ?></td>
                <td><?= h(reportAssenzePeriodo($riga)) ?></td>
                <td><?= h((string)$riga['data_creazione']) ?></td>
                <td><?= h((string)$riga['oggetto']) ?></td>
                <td><?= h((string)$riga['note_richiedente']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    exit;
}

$tipologie = $pdo->query("SELECT codice, descrizione FROM hr_tipologie_evento WHERE attivo = 1 ORDER BY ordinamento, descrizione")->fetchAll(PDO::FETCH_ASSOC);
$stati = $pdo->query("SELECT codice, descrizione FROM hr_stati_richiesta WHERE attivo = 1 ORDER BY ordinamento, descrizione")->fetchAll(PDO::FETCH_ASSOC);
$queryExport = $_GET;
$queryExport['export'] = 'excel';
$urlExport = 'report_assenze.php?' . http_build_query($queryExport);

layoutHeader('Report assenze');
?>

<style>
    .report-filters-grid {
        display: grid;
        grid-template-columns: minmax(150px, 1fr) minmax(170px, 1fr) minmax(140px, 0.8fr) minmax(140px, 0.8fr) minmax(220px, 1.2fr) auto auto auto;
        gap: 12px;
        align-items: end;
    }
    .report-filters-grid .form-group {
        margin: 0;
    }
    .report-filters-grid input,
    .report-filters-grid select {
        width: 100%;
        min-height: 42px;
    }
    .report-actions-inline {
        display: flex;
        gap: 8px;
        align-items: center;
        white-space: nowrap;
    }
    @media (max-width: 1100px) {
        .report-filters-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .report-actions-inline {
            grid-column: 1 / -1;
            flex-wrap: wrap;
        }
    }
    @media (max-width: 700px) {
        .report-filters-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Report assenze</h1>
            <div class="meta">Consulta, filtra ed esporta le richieste HR visualizzate.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="approvazioni_assenze.php"><i class="la la-check-circle" aria-hidden="true"></i> Approvazioni</a>
        </div>
    </div>
</div>

<div class="card card-compact">
    <form method="get">
        <div class="report-filters-grid">
            <div class="form-group">
                <label for="stato">Stato</label>
                <select name="stato" id="stato">
                    <option value="">Tutti</option>
                    <?php foreach ($stati as $stato): ?>
                        <option value="<?= h((string)$stato['codice']) ?>" <?= $filtroStato === (string)$stato['codice'] ? 'selected' : '' ?>><?= h((string)$stato['descrizione']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tipologia">Tipologia</label>
                <select name="tipologia" id="tipologia">
                    <option value="">Tutte</option>
                    <?php foreach ($tipologie as $tipologia): ?>
                        <option value="<?= h((string)$tipologia['codice']) ?>" <?= $filtroTipologia === (string)$tipologia['codice'] ? 'selected' : '' ?>><?= h((string)$tipologia['descrizione']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="data_da">Dal giorno</label>
                <input type="date" name="data_da" id="data_da" value="<?= h($filtroDataDa) ?>">
            </div>
            <div class="form-group">
                <label for="data_a">Al giorno</label>
                <input type="date" name="data_a" id="data_a" value="<?= h($filtroDataA) ?>">
            </div>
            <div class="form-group">
                <label for="utente">Dipendente</label>
                <input type="search" name="utente" id="utente" value="<?= h($filtroUtente) ?>" placeholder="Nome, cognome o username">
            </div>
            <div class="report-actions-inline">
                <button type="submit" class="btn btn-primary"><i class="la la-filter" aria-hidden="true"></i> Filtra</button>
                <a class="btn btn-light" href="report_assenze.php">Reset</a>
                <a class="btn btn-light" href="<?= h($urlExport) ?>"><i class="la la-file-excel" aria-hidden="true"></i> Esporta Excel</a>
            </div>
        </div>
    </form>
</div>

<div class="card card-wide">
    <div class="section-head">
        <div>
            <h2>Risultati</h2>
            <div class="meta"><?= count($righe) ?> richieste trovate.</div>
        </div>
    </div>

    <?php if ($erroreReport !== ''): ?>
        <div class="errore"><?= h($erroreReport) ?></div>
    <?php elseif (!$righe): ?>
        <div class="info-box">Nessuna richiesta corrisponde ai filtri selezionati.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Codice</th>
                    <th>Stato</th>
                    <th>Tipologia</th>
                    <th>Dipendente</th>
                    <th>Periodo</th>
                    <th>Creata il</th>
                    <th>Oggetto</th>
                    <th>Note</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($righe as $riga): ?>
                    <tr>
                        <td><strong><?= h((string)$riga['codice_richiesta']) ?></strong></td>
                        <td><?= h((string)$riga['stato']) ?></td>
                        <td><?= h((string)$riga['tipologia']) ?></td>
                        <td><?= h(trim((string)$riga['richiedente']) !== '' ? (string)$riga['richiedente'] : (string)$riga['username']) ?></td>
                        <td><?= h(reportAssenzePeriodo($riga)) ?></td>
                        <td><?= h((string)$riga['data_creazione']) ?></td>
                        <td><strong><?= h((string)$riga['oggetto']) ?></strong></td>
                        <td><?= nl2br(h((string)$riga['note_richiedente'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
