<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui.php';

richiediPermessoLettura('calendario_assenze');

$pdo = db();
$idUtente = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoConfigurare = haPermessoScrittura('configurazione_assenze');

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrNomeMese(int $mese, int $anno): string
{
    $nomi = [
        1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile',
        5 => 'Maggio', 6 => 'Giugno', 7 => 'Luglio', 8 => 'Agosto',
        9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre',
    ];

    return ($nomi[$mese] ?? (string)$mese) . ' ' . $anno;
}

function hrNomeGiornoBreve(DateTimeInterface $data): string
{
    $nomi = [
        1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Gio', 5 => 'Ven', 6 => 'Sab', 7 => 'Dom',
    ];

    return $nomi[(int)$data->format('N')] ?? '';
}

function hrNomeUtente(array $row): string
{
    $nome = trim((string)($row['nome'] ?? ''));
    $cognome = trim((string)($row['cognome'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    return $nominativo !== '' ? $nominativo : ($username !== '' ? $username : ('Utente #' . (int)($row['id_utente'] ?? 0)));
}

function hrIdsDirettiCalendario(PDO $pdo, int $idUtente): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT ro.id_utente
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro
            ON tro.id_tipo_relazione = ro.id_tipo_relazione
           AND tro.attivo = 1
           AND tro.codice IN ('RESPONSABILE_DIRETTO', 'RESPONSABILE_FUNZIONALE')
         INNER JOIN aut_utenti u
            ON u.id_utente = ro.id_utente
           AND u.attivo = 1
         WHERE ro.id_utente_collegato = :id_utente
           AND ro.attiva = 1
           AND ro.data_inizio <= CURDATE()
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function hrIdsGruppoCalendario(PDO $pdo, int $idUtente): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT gu2.id_utente
         FROM hr_gruppi_utenti gu1
         INNER JOIN hr_gruppi_utenti gu2
            ON gu2.id_gruppo_lavoro = gu1.id_gruppo_lavoro
           AND gu2.attivo = 1
           AND gu2.data_inizio <= CURDATE()
           AND (gu2.data_fine IS NULL OR gu2.data_fine >= CURDATE())
         INNER JOIN aut_utenti u
            ON u.id_utente = gu2.id_utente
           AND u.attivo = 1
         WHERE gu1.id_utente = :id_utente
           AND gu1.attivo = 1
           AND gu1.data_inizio <= CURDATE()
           AND (gu1.data_fine IS NULL OR gu1.data_fine >= CURDATE())"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
}

function hrScopeUtentiCalendario(PDO $pdo, int $idUtente, bool $puoConfigurare): array
{
    if ($puoConfigurare) {
        $stmt = $pdo->query(
            "SELECT id_utente, nome, cognome, username, 0 AS scope_gerarchia, 0 AS scope_gruppo
             FROM aut_utenti
             WHERE attivo = 1
             ORDER BY nome, cognome, username"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $map = [];
    $map[$idUtente] = ['scope_gerarchia' => false, 'scope_gruppo' => false];

    foreach (hrIdsDirettiCalendario($pdo, $idUtente) as $uid) {
        if (!isset($map[$uid])) {
            $map[$uid] = ['scope_gerarchia' => false, 'scope_gruppo' => false];
        }
        $map[$uid]['scope_gerarchia'] = true;
    }

    foreach (hrIdsGruppoCalendario($pdo, $idUtente) as $uid) {
        if ($uid === $idUtente) {
            continue;
        }
        if (!isset($map[$uid])) {
            $map[$uid] = ['scope_gerarchia' => false, 'scope_gruppo' => false];
        }
        $map[$uid]['scope_gruppo'] = true;
    }

    $ids = array_keys($map);
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT id_utente, nome, cognome, username
         FROM aut_utenti
         WHERE attivo = 1
           AND id_utente IN ($placeholders)
         ORDER BY nome, cognome, username"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $uid = (int)$row['id_utente'];
        $row['scope_gerarchia'] = !empty($map[$uid]['scope_gerarchia']) ? 1 : 0;
        $row['scope_gruppo'] = !empty($map[$uid]['scope_gruppo']) ? 1 : 0;
    }
    unset($row);

    return $rows;
}

function hrColoreValido(?string $colore): string
{
    $colore = trim((string)$colore);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $colore) ? $colore : '#6c757d';
}


function hrMostraDettaglioCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare): bool
{
    $idRichiedente = (int)($row['id_utente_richiedente'] ?? 0);

    if ($idRichiedente === $idUtenteCorrente) {
        return true;
    }

    if ($puoConfigurare) {
        return (int)($row['mostra_dettaglio_hr'] ?? 1) === 1;
    }

    $scope = $scopeMap[$idRichiedente] ?? ['gerarchia' => false, 'gruppo' => false];

    if (!empty($scope['gerarchia'])) {
        return (int)($row['mostra_dettaglio_responsabili'] ?? 1) === 1;
    }

    if (!empty($scope['gruppo'])) {
        return (int)($row['mostra_dettaglio_colleghi'] ?? 0) === 1;
    }

    return false;
}

function hrEtichettaCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare): string
{
    $statoPresenza = trim((string)($row['stato_presenza_breve'] ?: $row['stato_presenza'] ?: 'Assente'));
    $dettaglio = trim((string)($row['descrizione_calendario'] ?: $row['tipologia'] ?: 'Assenza'));

    if (hrMostraDettaglioCalendario($row, $scopeMap, $idUtenteCorrente, $puoConfigurare)) {
        return $dettaglio !== '' ? $dettaglio : $statoPresenza;
    }

    return $statoPresenza !== '' ? $statoPresenza : 'Assente';
}

function hrPeriodoEvento(array $event): string
{
    $tipo = strtoupper((string)($event['tipo_periodo'] ?? ''));
    $oraDa = trim((string)($event['ora_da'] ?? ''));
    $oraA = trim((string)($event['ora_a'] ?? ''));

    if ($tipo === 'ORE' && $oraDa !== '' && $oraA !== '') {
        return $oraDa . ' - ' . $oraA;
    }

    return 'Giornata';
}

$anno = (int)($_GET['anno'] ?? date('Y'));
$mese = (int)($_GET['mese'] ?? date('n'));

if ($mese < 1 || $mese > 12) {
    $mese = (int)date('n');
}
if ($anno < 2020 || $anno > 2100) {
    $anno = (int)date('Y');
}

$primoDelMese = new DateTimeImmutable(sprintf('%04d-%02d-01', $anno, $mese));
$ultimoDelMese = $primoDelMese->modify('last day of this month');
$inizioGriglia = $primoDelMese->modify('-' . ((int)$primoDelMese->format('N') - 1) . ' days');
$fineGriglia = $ultimoDelMese->modify('+' . (7 - (int)$ultimoDelMese->format('N')) . ' days');
$prev = $primoDelMese->modify('-1 month');
$next = $primoDelMese->modify('+1 month');
$annoDa = max(2020, $anno - 2);
$annoA = min(2100, $anno + 2);

$scopeUtenti = hrScopeUtentiCalendario($pdo, $idUtente, $puoConfigurare);
$scopeMap = [];
$scopeIds = [];

foreach ($scopeUtenti as $u) {
    $uid = (int)$u['id_utente'];
    $scopeIds[] = $uid;
    $scopeMap[$uid] = [
        'label' => hrNomeUtente($u),
        'gerarchia' => (int)($u['scope_gerarchia'] ?? 0) === 1,
        'gruppo' => (int)($u['scope_gruppo'] ?? 0) === 1,
    ];
}

$eventsByDay = [];
$daysWithEvents = [];
$legendMap = [];
$error = '';
$totalUsers = count($scopeIds);

try {
    if ($totalUsers > 0) {
        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        $sql = "
            SELECT
                r.id_richiesta,
                r.id_utente_richiedente,
                p.data_da,
                p.data_a,
                p.ora_da,
                p.ora_a,
                p.tipo_periodo,
                te.codice AS codice_tipologia,
                te.descrizione AS tipologia,
                te.descrizione_calendario,
                te.colore_calendario,
                te.disturbabile,
                te.mostra_dettaglio_colleghi,
                te.mostra_dettaglio_responsabili,
                te.mostra_dettaglio_hr,
                sp.descrizione_breve AS stato_presenza_breve,
                sp.descrizione AS stato_presenza,
                u.nome,
                u.cognome,
                u.username
            FROM hr_richieste r
            INNER JOIN hr_stati_richiesta sr
                ON sr.id_stato_richiesta = r.id_stato_richiesta
               AND sr.codice = 'APPROVATA'
            INNER JOIN hr_richieste_periodi p
                ON p.id_richiesta = r.id_richiesta
            INNER JOIN hr_tipologie_evento te
                ON te.id_tipologia_evento = r.id_tipologia_evento
               AND te.visibile_calendario = 1
               AND te.attivo = 1
            INNER JOIN hr_stati_presenza sp
                ON sp.id_stato_presenza = te.id_stato_presenza
            INNER JOIN aut_utenti u
                ON u.id_utente = r.id_utente_richiedente
            WHERE r.id_utente_richiedente IN ($placeholders)
              AND p.data_da <= ?
              AND p.data_a >= ?
            ORDER BY p.data_da, p.ora_da, u.nome, u.cognome, u.username
        ";

        $params = $scopeIds;
        $params[] = $fineGriglia->format('Y-m-d');
        $params[] = $inizioGriglia->format('Y-m-d');

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $start = new DateTimeImmutable((string)$row['data_da']);
            $end = new DateTimeImmutable((string)$row['data_a']);
            $label = hrEtichettaCalendario($row, $scopeMap, $idUtente, $puoConfigurare);
            $color = hrColoreValido((string)($row['colore_calendario'] ?? ''));

            $legendMap[$label] = $color;

            $event = [
                'id_richiesta' => (int)$row['id_richiesta'],
                'id_utente' => (int)$row['id_utente_richiedente'],
                'nome' => hrNomeUtente($row),
                'tipologia' => $label,
                'colore' => $color,
                'stato_presenza' => (string)($row['stato_presenza_breve'] ?: $row['stato_presenza']),
                'disturbabile' => (int)$row['disturbabile'] === 1,
                'tipo_periodo' => (string)$row['tipo_periodo'],
                'ora_da' => $row['ora_da'] ? substr((string)$row['ora_da'], 0, 5) : '',
                'ora_a' => $row['ora_a'] ? substr((string)$row['ora_a'], 0, 5) : '',
            ];

            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $key = $day->format('Y-m-d');
                if ($key < $inizioGriglia->format('Y-m-d') || $key > $fineGriglia->format('Y-m-d')) {
                    continue;
                }
                $eventsByDay[$key][] = $event;
                $daysWithEvents[$key] = true;
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$daySummaries = [];
$dayDetailsJson = [];

foreach ($eventsByDay as $dayKey => $events) {
    $summaryMap = [];
    $detailMap = [];

    foreach ($events as $event) {
        $label = trim((string)$event['tipologia']);
        if ($label === '') {
            $label = 'Assenza';
        }

        if (!isset($summaryMap[$label])) {
            $summaryMap[$label] = ['count' => 0, 'color' => $event['colore']];
        }
        $summaryMap[$label]['count']++;

        if (!isset($detailMap[$label])) {
            $detailMap[$label] = [
                'color' => $event['colore'],
                'items' => [],
            ];
        }
        $detailMap[$label]['items'][] = $event;
    }

    ksort($summaryMap);
    ksort($detailMap);
    $daySummaries[$dayKey] = $summaryMap;
    $dayDetailsJson[$dayKey] = $detailMap;
}

ksort($legendMap);
$dayDetailsJsonEncoded = json_encode($dayDetailsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($dayDetailsJsonEncoded === false) {
    $dayDetailsJsonEncoded = '{}';
}

$todayKey = date('Y-m-d');
$selectedDay = trim((string)($_GET['giorno'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDay)) {
    $selectedDay = ($todayKey >= $inizioGriglia->format('Y-m-d') && $todayKey <= $fineGriglia->format('Y-m-d'))
        ? $todayKey
        : $primoDelMese->format('Y-m-d');
}
$selectedDateForJs = json_encode($selectedDay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($selectedDateForJs === false) {
    $selectedDateForJs = 'null';
}

layoutHeader('Calendario assenze');
?>


<div class="hr-cal-page">
<?php renderHrAlert($error, 'danger'); ?>

<section class="hr-cal-layout">
    <aside class="card hr-day-panel" aria-live="polite">
        <div class="hr-day-panel-head">
            <h2 id="hrDayPanelTitle">Situazione giorno</h2>
            <div class="hr-day-nav" aria-label="Navigazione giorno">
                <button type="button" class="btn hr-icon-btn hr-icon-btn-primary" id="hrPrevDay" title="Giorno precedente" aria-label="Giorno precedente"><i class="la la-angle-left" aria-hidden="true"></i></button>
                <button type="button" class="btn hr-icon-btn hr-icon-btn-secondary" id="hrTodayDay" title="Oggi" aria-label="Oggi"><i class="la la-calendar" aria-hidden="true"></i></button>
                <button type="button" class="btn hr-icon-btn hr-icon-btn-primary" id="hrNextDay" title="Giorno successivo" aria-label="Giorno successivo"><i class="la la-angle-right" aria-hidden="true"></i></button>
            </div>
        </div>
        <div id="hrDayPanelBody"></div>
    </aside>

    <div class="card">
        <div class="hr-calendar-head">
            <div>
                <h1><?= h(hrNomeMese($mese, $anno)) ?></h1>
                <div class="hr-calendar-subtitle">Clicca su un giorno per vedere il dettaglio raggruppato per tipologia.</div>
            </div>
            <div class="hr-cal-toolbar" aria-label="Navigazione mese">
                <a class="btn hr-icon-btn hr-icon-btn-primary" href="calendario_assenze.php?mese=<?= (int)$prev->format('n') ?>&anno=<?= (int)$prev->format('Y') ?>" title="Mese precedente" aria-label="Mese precedente"><i class="la la-angle-left" aria-hidden="true"></i></a>
                <a class="btn hr-cal-today-btn" href="calendario_assenze.php" title="Torna al mese corrente" aria-label="Torna al mese corrente"><i class="la la-calendar" aria-hidden="true"></i><span>Oggi</span></a>
                <a class="btn hr-icon-btn hr-icon-btn-primary" href="calendario_assenze.php?mese=<?= (int)$next->format('n') ?>&anno=<?= (int)$next->format('Y') ?>" title="Mese successivo" aria-label="Mese successivo"><i class="la la-angle-right" aria-hidden="true"></i></a>
                <form class="hr-month-jump" method="get" action="calendario_assenze.php" aria-label="Vai a un mese specifico">
                    <select name="mese" aria-label="Mese">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $mese ? 'selected' : '' ?>><?= h(hrNomeMese($m, $anno)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="anno" aria-label="Anno">
                        <?php for ($a = $annoDa; $a <= $annoA; $a++): ?>
                            <option value="<?= $a ?>" <?= $a === $anno ? 'selected' : '' ?>><?= $a ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn">Vai</button>
                </form>
            </div>
        </div>
        <div class="hr-cal-grid" aria-label="Calendario mensile">
            <?php foreach (['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'] as $giorno): ?>
                <div class="hr-cal-weekday"><?= h($giorno) ?></div>
            <?php endforeach; ?>
            <?php for ($day = $inizioGriglia; $day <= $fineGriglia; $day = $day->modify('+1 day')): ?>
                <?php
                $key = $day->format('Y-m-d');
                $isCurrentMonth = (int)$day->format('n') === $mese;
                $isToday = $key === date('Y-m-d');
                $summaries = $daySummaries[$key] ?? [];
                $hasEvents = count($summaries) > 0;
                $isWeekend = (int)$day->format('N') >= 6;
                $classes = 'hr-cal-day' . (!$isCurrentMonth ? ' is-muted' : '') . ($isWeekend ? ' is-weekend' : '') . ($isToday ? ' is-today' : '') . ($key === $selectedDay ? ' is-selected' : '') . ($hasEvents ? ' has-events' : '');
                ?>
                <div class="<?= h($classes) ?>" data-day="<?= h($key) ?>" role="button" tabindex="0" aria-label="<?= h(hrNomeGiornoBreve($day) . ' ' . $day->format('d/m/Y')) ?>">
                    <span class="hr-cal-date-line">
                        <span class="hr-cal-day-number"><?= h($day->format('j')) ?></span>
                        <span class="hr-cal-mobile-weekday"><?= h(hrNomeGiornoBreve($day)) ?></span>
                        <?php if ($isToday): ?><span class="hr-cal-today-badge">Oggi</span><?php endif; ?>
                    </span>
                    <?php foreach ($summaries as $label => $summary): ?>
                        <span class="hr-cal-event-line">
                            <span class="hr-dot" style="--dot-color: <?= h($summary['color']) ?>"></span>
                            <span><?= h(mb_strtolower((string)$label, 'UTF-8')) ?>: <strong><?= (int)$summary['count'] ?></strong></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
</div>

<script>
(function () {
    const details = <?= $dayDetailsJsonEncoded ?>;
    const selectedInitialDay = <?= $selectedDateForJs ?>;
    const title = document.getElementById('hrDayPanelTitle');
    const body = document.getElementById('hrDayPanelBody');

    function escapeHtml(value) {
        return String(value).replace(/[&<>'"]/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char];
        });
    }

    function formatDate(isoDate) {
        const parts = String(isoDate).split('-');
        if (parts.length !== 3) return isoDate;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function renderDay(day) {
        const groups = details[day] || {};
        title.textContent = 'Situazione del ' + formatDate(day);
        let html = '';

        const labels = Object.keys(groups);
        if (labels.length === 0) {
            body.innerHTML = '<p class="hr-day-panel-empty">Nessuna assenza visibile per questo giorno.</p>';
            return;
        }

        labels.forEach(function (label) {
            const group = groups[label];
            const color = group.color || '#6c757d';
            const items = group.items || [];
            html += '<div class="hr-detail-group">';
            html += '<div class="hr-detail-group-title"><span class="hr-dot hr-dot-lg" style="--dot-color:' + escapeHtml(color) + '"></span>' + escapeHtml(label) + ' <span class="badge">' + items.length + '</span></div>';
            items.forEach(function (item) {
                let meta = '';
                if (item.tipo_periodo === 'ORE' && item.ora_da && item.ora_a) {
                    meta = escapeHtml(item.ora_da + ' - ' + item.ora_a);
                } else {
                    meta = 'Giornata';
                }
                html += '<div class="hr-detail-row"><div class="hr-detail-name">' + escapeHtml(item.nome || '') + '</div><div class="hr-detail-meta">' + meta + '</div></div>';
            });
            html += '</div>';
        });

        body.innerHTML = html;
    }

    function monthUrlForDay(day) {
        const date = new Date(day + 'T12:00:00');
        return 'calendario_assenze.php?mese=' + (date.getMonth() + 1) + '&anno=' + date.getFullYear() + '&giorno=' + encodeURIComponent(day);
    }

    function addDays(day, delta) {
        const date = new Date(day + 'T12:00:00');
        date.setDate(date.getDate() + delta);
        return date.toISOString().slice(0, 10);
    }

    let currentDay = selectedInitialDay || new Date().toISOString().slice(0, 10);

    function selectDay(day) {
        currentDay = day;
        document.querySelectorAll('.hr-cal-day.is-selected').forEach(function (cell) {
            cell.classList.remove('is-selected');
        });
        const cell = document.querySelector('.hr-cal-day[data-day="' + day + '"]');
        if (cell) {
            cell.classList.add('is-selected');
        }
        renderDay(day);
    }

    function goToDay(day) {
        const cell = document.querySelector('.hr-cal-day[data-day="' + day + '"]');
        if (!cell) {
            window.location.href = monthUrlForDay(day);
            return;
        }
        selectDay(day);
    }

    const prevDayButton = document.getElementById('hrPrevDay');
    const nextDayButton = document.getElementById('hrNextDay');
    const todayDayButton = document.getElementById('hrTodayDay');
    if (prevDayButton) prevDayButton.addEventListener('click', function () { goToDay(addDays(currentDay, -1)); });
    if (nextDayButton) nextDayButton.addEventListener('click', function () { goToDay(addDays(currentDay, 1)); });
    if (todayDayButton) todayDayButton.addEventListener('click', function () { goToDay(new Date().toISOString().slice(0, 10)); });

    document.querySelectorAll('.hr-cal-day').forEach(function (cell) {
        cell.addEventListener('click', function () {
            selectDay(cell.getAttribute('data-day'));
        });
        cell.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                selectDay(cell.getAttribute('data-day'));
            }
        });
    });

    selectDay(selectedInitialDay || new Date().toISOString().slice(0, 10));
}());
</script>

<?php layoutFooter(); ?>
