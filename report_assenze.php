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

function xlsxText(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
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

function reportExcelColumnName(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function reportExcelCell(string $value, int $row, int $col, int $style = 0): string
{
    $ref = reportExcelColumnName($col) . $row;
    $styleAttr = $style > 0 ? ' s="' . $style . '"' : '';
    return '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '><is><t>' . xlsxText($value) . '</t></is></c>';
}

function reportExcelRow(array $values, int $row, int $style = 0): string
{
    $cells = '';
    $col = 1;
    foreach ($values as $value) {
        $cells .= reportExcelCell((string)$value, $row, $col, $style);
        $col++;
    }
    return '<row r="' . $row . '">' . $cells . '</row>';
}

function reportOutputXlsx(array $righe): void
{
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo 'Impossibile generare il file XLSX: estensione ZipArchive non disponibile sul server.';
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'hr_report_');
    if ($tmp === false) {
        http_response_code(500);
        echo 'Impossibile creare il file temporaneo XLSX.';
        exit;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        http_response_code(500);
        echo 'Impossibile creare il file XLSX.';
        exit;
    }

    $headers = ['Codice', 'Stato', 'Tipologia', 'Dipendente', 'Username', 'Periodo', 'Creata il', 'Oggetto', 'Note'];
    $rows = reportExcelRow($headers, 1, 1);
    $rowNumber = 2;

    foreach ($righe as $riga) {
        $rows .= reportExcelRow([
            (string)($riga['codice_richiesta'] ?? ''),
            (string)($riga['stato'] ?? ''),
            (string)($riga['tipologia'] ?? ''),
            trim((string)($riga['richiedente'] ?? '')) !== '' ? (string)$riga['richiedente'] : (string)($riga['username'] ?? ''),
            (string)($riga['username'] ?? ''),
            reportAssenzePeriodo($riga),
            (string)($riga['data_creazione'] ?? ''),
            (string)($riga['oggetto'] ?? ''),
            (string)($riga['note_richiedente'] ?? ''),
        ], $rowNumber);
        $rowNumber++;
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report assenze" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="28" customWidth="1"/><col min="2" max="3" width="16" customWidth="1"/><col min="4" max="5" width="24" customWidth="1"/><col min="6" max="7" width="22" customWidth="1"/><col min="8" max="9" width="35" customWidth="1"/></cols><sheetData>' . $rows . '</sheetData></worksheet>');
    $zip->close();

    $nomeFile = 'HR_ReportAssenze_' . date('Y-m-d_H-i-s') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nomeFile . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Pragma: no-cache');
    header('Expires: 0');
    readfile($tmp);
    @unlink($tmp);
    exit;
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
    reportOutputXlsx($righe);
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
        grid-template-columns: minmax(150px, 1fr) minmax(170px, 1fr) minmax(140px, 0.8fr) minmax(140px, 0.8fr) minmax(220px, 1.2fr) auto;
        gap: 12px;
        align-items: end;
    }
    .report-filters-grid .form-group { margin: 0; }
    .report-filters-grid input,
    .report-filters-grid select,
    .report-input {
        width: 100%;
        min-height: 42px;
        border: 1px solid #d5deea;
        border-radius: 10px;
        padding: 9px 12px;
        background: #fff;
        color: #172033;
        font: inherit;
        box-sizing: border-box;
    }
    .report-filters-grid input:focus,
    .report-filters-grid select:focus,
    .report-input:focus {
        outline: none;
        border-color: #0b63f6;
        box-shadow: 0 0 0 3px rgba(11, 99, 246, 0.12);
    }
    .report-actions-inline {
        display: flex;
        gap: 8px;
        align-items: center;
        white-space: nowrap;
    }
    @media (max-width: 1100px) {
        .report-filters-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .report-actions-inline { grid-column: 1 / -1; flex-wrap: wrap; }
    }
    @media (max-width: 700px) {
        .report-filters-grid { grid-template-columns: 1fr; }
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
                <input class="report-input" type="text" name="utente" id="utente" value="<?= h($filtroUtente) ?>" placeholder="Nome, cognome o username">
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
                    <th>Codice</th><th>Stato</th><th>Tipologia</th><th>Dipendente</th><th>Periodo</th><th>Creata il</th><th>Oggetto</th><th>Note</th>
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