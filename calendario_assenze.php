<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('calendario_assenze');

$pdo = db();
$idUtente = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoConfigurare = haPermessoLettura('configurazione_assenze');

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrMeseLabel(int $anno, int $mese): string
{
    $nomi = [1 => 'Gennaio', 'Febbraio', 'Marzo', 'Aprile', 'Maggio', 'Giugno', 'Luglio', 'Agosto', 'Settembre', 'Ottobre', 'Novembre', 'Dicembre'];
    return ($nomi[$mese] ?? (string)$mese) . ' ' . $anno;
}

function hrNomeUtente(array $row): string
{
    $nome = trim((string)($row['nome'] ?? ''));
    $cognome = trim((string)($row['cognome'] ?? ''));
    $username = trim((string)($row['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);
    if ($nominativo !== '') {
        return $nominativo;
    }
    return $username !== '' ? $username : ('Utente #' . (int)($row['id_utente'] ?? 0));
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
        $stmt = $pdo->query("SELECT id_utente, nome, cognome, username, 0 AS scope_gerarchia, 0 AS scope_gruppo FROM aut_utenti WHERE attivo = 1 ORDER BY nome, cognome, username");
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
    $stmt = $pdo->prepare("SELECT id_utente, nome, cognome, username FROM aut_utenti WHERE attivo = 1 AND id_utente IN ($placeholders) ORDER BY nome, cognome, username");
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
$visibleUsersByDay = [];
$daysWithEvents = [];
$totalUsers = count($scopeIds);
$error = '';

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
                te.descrizione AS tipologia,
                te.descrizione_calendario,
                te.colore_calendario,
                sp.descrizione_breve AS stato_presenza_breve,
                sp.descrizione AS stato_presenza,
                te.disturbabile,
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
            $event = [
                'id_richiesta' => (int)$row['id_richiesta'],
                'id_utente' => (int)$row['id_utente_richiedente'],
                'nome' => trim((string)$row['nome'] . ' ' . (string)$row['cognome']) ?: (string)$row['username'],
                'tipologia' => (string)($row['descrizione_calendario'] ?: $row['tipologia']),
                'colore' => (string)($row['colore_calendario'] ?: '#6c757d'),
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
                $visibleUsersByDay[$key][$event['id_utente']] = true;
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
        $summaryMap[$label] = ($summaryMap[$label] ?? 0) + 1;
        if (!isset($detailMap[$label])) {
            $detailMap[$label] = [];
        }
        $detailMap[$label][] = $event;
    }
    ksort($summaryMap);
    ksort($detailMap);
    $daySummaries[$dayKey] = $summaryMap;
    $dayDetailsJson[$dayKey] = $detailMap;
}
$dayDetailsJsonEncoded = json_encode($dayDetailsJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($dayDetailsJsonEncoded === false) {
    $dayDetailsJsonEncoded = '{}';
}

layoutHeader('Calendario assenze');
?>
<style>
.scope-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #c9d3df;border-radius:999px;background:#fff;color:#24364b;font-size:14px;margin:0 8px 8px 0;}
.scope-chip .scope-icon{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;font-size:12px;font-weight:700;background:#eef3f8;color:#425466;border:1px solid #c9d3df;}
.scope-chip .scope-icon-group{background:#eef8ff;color:#0b7285;}
.scope-chip .scope-icon-hierarchy{background:#f3efff;color:#5f3dc4;}
.calendar-day-summary{display:flex;align-items:center;gap:6px;}
.calendar-day-summary .calendar-dot{width:8px;height:8px;}
</style>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Calendario assenze</h1>
            <div class="meta">
                Vista mensile delle assenze approvate e degli stati di presenza del tuo perimetro. Per ogni giorno puoi capire chi è assente, in smart working o in trasferta e quante persone risultano presenti.
            </div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="calendario_assenze.php?mese=<?= (int)$prev->format('n') ?>&amp;anno=<?= (int)$prev->format('Y') ?>">◀ Mese precedente</a>
            <a class="btn btn-light" href="calendario_assenze.php?mese=<?= (int)date('n') ?>&amp;anno=<?= (int)date('Y') ?>">Oggi</a>
            <a class="btn btn-light" href="calendario_assenze.php?mese=<?= (int)$next->format('n') ?>&amp;anno=<?= (int)$next->format('Y') ?>">Mese successivo ▶</a>
        </div>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="errore"><?= h($error) ?></div>
<?php endif; ?>

<div class="dashboard-grid" style="margin-bottom: 22px;">
    <div class="dashboard-box">
        <h3>Mese visualizzato</h3>
        <div class="kpi-number" style="font-size: 24px;"><?= h(hrMeseLabel($anno, $mese)) ?></div>
    </div>
    <div class="dashboard-box">
        <h3>Persone nel perimetro</h3>
        <div class="kpi-number"><?= $totalUsers ?></div>
    </div>
    <div class="dashboard-box">
        <h3>Giorni con eventi</h3>
        <div class="kpi-number"><?= count($daysWithEvents) ?></div>
    </div>
    <div class="dashboard-box">
        <h3>Legenda</h3>
        <div class="calendar-legend">
            <span class="calendar-chip"><span class="calendar-dot" style="background:#dc3545"></span>Assente</span>
            <span class="calendar-chip"><span class="calendar-dot" style="background:#17a2b8"></span>Smart</span>
            <span class="calendar-chip"><span class="calendar-dot" style="background:#6f42c1"></span>Trasferta</span>
        </div>
    </div>
</div>

<div class="card card-wide">
    <div class="calendar-month-title"><?= h(hrMeseLabel($anno, $mese)) ?></div>
    <div class="calendar-grid calendar-weekdays">
        <div>Lun</div>
        <div>Mar</div>
        <div>Mer</div>
        <div>Gio</div>
        <div>Ven</div>
        <div>Sab</div>
        <div>Dom</div>
    </div>

    <div class="calendar-grid">
        <?php for ($day = $inizioGriglia; $day <= $fineGriglia; $day = $day->modify('+1 day')): ?>
            <?php
            $key = $day->format('Y-m-d');
            $isCurrentMonth = (int)$day->format('n') === $mese;
            $isToday = $key === date('Y-m-d');
            $summaries = $daySummaries[$key] ?? [];
            $hasEvents = count($summaries) > 0;
            ?>
            <div class="calendar-cell calendar-cell-clickable <?= $isCurrentMonth ? '' : 'calendar-cell-muted' ?> <?= $isToday ? 'calendar-cell-today' : '' ?>"<?= $hasEvents ? ' tabindex="0" role="button" aria-label="Apri dettaglio del giorno ' . h($key) . '" data-day="' . h($key) . '"' : '' ?>>
                <div class="calendar-cell-head">
                    <span class="calendar-day-number"><?= (int)$day->format('j') ?></span>
                </div>
                <div class="calendar-events">
                    <?php if (!$hasEvents): ?>
                        <div class="calendar-empty">Nessuna assenza visibile</div>
                    <?php else: ?>
                        <div class="calendar-day-summaries">
                            <?php foreach ($summaries as $label => $count): ?>
                                <?php $summaryColor = $dayDetailsJson[$key][$label][0]['colore'] ?? '#6c757d'; ?>
                                <span class="calendar-day-summary"><span class="calendar-dot" style="background:<?= h((string)$summaryColor) ?>"></span><?= h(mb_strtolower((string)$label, 'UTF-8')) ?>: <?= (int)$count ?></span>
                            <?php endforeach; ?>
                        </div>
                        <span class="calendar-open-detail">Clicca per il dettaglio</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="calendar-modal-backdrop" id="calendar-modal-backdrop" aria-hidden="true">
    <div class="calendar-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-modal-title">
        <div class="calendar-modal-head">
            <div>
                <h2 id="calendar-modal-title">Dettaglio giorno</h2>
            </div>
            <button type="button" class="btn btn-light" id="calendar-modal-close">Chiudi</button>
        </div>
        <div id="calendar-modal-body"></div>
    </div>
</div>

<script>
(function () {
    const details = <?= $dayDetailsJsonEncoded ?>;
    const backdrop = document.getElementById('calendar-modal-backdrop');
    const body = document.getElementById('calendar-modal-body');
    const title = document.getElementById('calendar-modal-title');
    const closeBtn = document.getElementById('calendar-modal-close');

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDateIso(iso) {
        const parts = String(iso).split('-');
        if (parts.length !== 3) return iso;
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function renderEventTime(event) {
        if (event.tipo_periodo === 'ORE' && event.ora_da && event.ora_a) {
            return '<span class="calendar-person-time">' + escapeHtml(event.ora_da) + ' - ' + escapeHtml(event.ora_a) + '</span>';
        }
        return '';
    }

    function openDay(dayKey) {
        const groups = details[dayKey] || {};
        title.textContent = 'Dettaglio del ' + formatDateIso(dayKey);
        let html = '';
        const labels = Object.keys(groups);
        if (labels.length === 0) {
            html = '<div class="meta">Nessuna assenza visibile.</div>';
        } else {
            labels.forEach(function (label) {
                const first = groups[label][0] || {};
                const color = first.colore || '#6c757d';
                html += '<section class="calendar-modal-group">';
                html += '<div class="calendar-modal-group-title">';
                html += '<span class="calendar-dot" style="background:' + escapeHtml(color) + '"></span>';
                html += '<span>' + escapeHtml(label) + '</span>';
                html += '<span class="calendar-count-badge">' + groups[label].length + '</span>';
                html += '</div>';
                html += '<ul class="calendar-person-list">';
                groups[label].forEach(function (event) {
                    html += '<li class="calendar-person-item">';
                    html += '<span class="calendar-person-name">' + escapeHtml(event.nome) + '</span>';
                    html += renderEventTime(event);
                    html += '</li>';
                });
                html += '</ul>';
                html += '</section>';
            });
        }
        body.innerHTML = html;
        backdrop.classList.add('is-open');
        backdrop.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        backdrop.classList.remove('is-open');
        backdrop.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
    }

    document.querySelectorAll('.calendar-cell-clickable').forEach(function (cell) {
        cell.addEventListener('click', function () {
            const dayKey = this.getAttribute('data-day');
            if (dayKey) {
                openDay(dayKey);
            }
        });
        cell.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                const dayKey = this.getAttribute('data-day');
                if (dayKey) {
                    openDay(dayKey);
                }
            }
        });
    });

    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (event) {
        if (event.target === backdrop) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && backdrop.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>

<div class="card card-wide">
    <h2>Persone nel perimetro del calendario</h2>
    <div class="meta">↕ = visibilità gerarchica · ⇄ = visibilità di gruppo</div>
    <?php if ($totalUsers === 0): ?>
        <div class="meta">Non risultano utenti visibili nel tuo perimetro.</div>
    <?php else: ?>
        <div class="calendar-people-list">
            <?php foreach ($scopeMap as $uid => $info): ?>
                <span class="scope-chip">
                    <span><?= h((string)$info['label']) ?></span>
                    <?php if (!empty($info['gerarchia'])): ?><span class="scope-icon scope-icon-hierarchy" title="visibilità gerarchica">↕</span><?php endif; ?>
                    <?php if (!empty($info['gruppo'])): ?><span class="scope-icon scope-icon-group" title="visibilità di gruppo">⇄</span><?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
