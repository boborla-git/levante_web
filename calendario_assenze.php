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
$puoVedereTutteAssenze = $puoConfigurare || haPermesso('azione.hr.assenze.visualizza_tutte', 'read');
$puoVedereTipologieAssenze = $puoConfigurare || haPermesso('azione.hr.assenze.visualizza_tipologie', 'read');
$puoVederePendentiGlobali = $puoConfigurare || haPermesso('azione.hr.assenze.visualizza_pendenti_globali', 'read');

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

function hrScopeUtentiCalendario(PDO $pdo, int $idUtente, bool $puoVedereTutteAssenze): array
{
    if ($puoVedereTutteAssenze) {
        $stmt = $pdo->query(
            "SELECT id_utente, nome, cognome, username, 0 AS scope_gerarchia, 0 AS scope_gruppo
             FROM aut_utenti
             WHERE attivo = 1
             ORDER BY cognome, nome, username"
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
         ORDER BY cognome, nome, username"
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


function hrMostraDettaglioCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare, bool $puoVedereTipologieAssenze): bool
{
    $idRichiedente = (int)($row['id_utente_richiedente'] ?? 0);

    if ($idRichiedente === $idUtenteCorrente) {
        return true;
    }

    if ($puoConfigurare || $puoVedereTipologieAssenze) {
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

function hrEtichettaCalendario(array $row, array $scopeMap, int $idUtenteCorrente, bool $puoConfigurare, bool $puoVedereTipologieAssenze): string
{
    $statoPresenza = trim((string)($row['stato_presenza_breve'] ?: $row['stato_presenza'] ?: 'Assente'));
    $dettaglio = trim((string)($row['descrizione_calendario'] ?: $row['tipologia'] ?: 'Assenza'));

    if (hrMostraDettaglioCalendario($row, $scopeMap, $idUtenteCorrente, $puoConfigurare, $puoVedereTipologieAssenze)) {
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


$vista = strtolower(trim((string)($_GET['vista'] ?? 'settimane')));
if (!in_array($vista, ['giorno', 'settimane', 'mese'], true)) {
    $vista = 'settimane';
}

// Per impostazione predefinita il calendario mostra soltanto le persone che
// hanno almeno un'assenza approvata o una richiesta in attesa nel periodo.
// "mostra=tutti" consente di espandere temporaneamente l'elenco completo.
$mostraTutti = strtolower(trim((string)($_GET['mostra'] ?? 'assenze'))) === 'tutti';
$mostraParam = $mostraTutti ? '&mostra=tutti' : '';

$oggi = new DateTimeImmutable('today');
$dataParam = trim((string)($_GET['data'] ?? ''));
try {
    $dataRif = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataParam) ? new DateTimeImmutable($dataParam) : $oggi;
} catch (Throwable $e) {
    $dataRif = $oggi;
}

if ($vista === 'giorno') {
    $inizioPeriodo = $dataRif;
    $finePeriodo = $dataRif;
    $prevData = $dataRif->modify('-1 day');
    $nextData = $dataRif->modify('+1 day');
    $titoloPeriodo = hrNomeGiornoBreve($dataRif) . ' ' . $dataRif->format('d/m/Y');
} elseif ($vista === 'mese') {
    $inizioPeriodo = $dataRif->modify('first day of this month');
    $finePeriodo = $dataRif->modify('last day of this month');
    $prevData = $dataRif->modify('-1 month');
    $nextData = $dataRif->modify('+1 month');
    $titoloPeriodo = hrNomeMese((int)$dataRif->format('n'), (int)$dataRif->format('Y'));
} else {
    $inizioPeriodo = $dataRif->modify('-' . ((int)$dataRif->format('N') - 1) . ' days');
    $finePeriodo = $inizioPeriodo->modify('+11 days');
    // due settimane lavorative: lun-ven + lun-ven; il range SQL include il weekend intermedio.
    $prevData = $dataRif->modify('-7 days');
    $nextData = $dataRif->modify('+7 days');
    $titoloPeriodo = $inizioPeriodo->format('d/m') . ' - ' . $finePeriodo->format('d/m/Y');
}

$scopeUtenti = hrScopeUtentiCalendario($pdo, $idUtente, $puoVedereTutteAssenze);
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

// Ordine righe: utente corrente, riporti diretti, membri team non duplicati.
// Per HR con visione globale: utente corrente, poi gli altri alfabeticamente.
usort($scopeUtenti, static function(array $a, array $b) use ($idUtente): int {
    $aid = (int)$a['id_utente'];
    $bid = (int)$b['id_utente'];
    if ($aid === $idUtente) return -1;
    if ($bid === $idUtente) return 1;
    $ag = (int)($a['scope_gerarchia'] ?? 0);
    $bg = (int)($b['scope_gerarchia'] ?? 0);
    if ($ag !== $bg) return $bg <=> $ag;
    $at = (int)($a['scope_gruppo'] ?? 0);
    $bt = (int)($b['scope_gruppo'] ?? 0);
    if ($at !== $bt) return $bt <=> $at;
    return strcasecmp(hrNomeUtente($a), hrNomeUtente($b));
});

function hrNomeCompatto(array $u, int $corrente): string
{
    $nome = trim((string)($u['nome'] ?? ''));
    $cognome = trim((string)($u['cognome'] ?? ''));
    $label = $cognome !== '' ? $cognome . ($nome !== '' ? ' ' . mb_strtoupper(mb_substr($nome, 0, 1, 'UTF-8'), 'UTF-8') . '.' : '') : hrNomeUtente($u);
    return $label . ((int)$u['id_utente'] === $corrente ? ' (tu)' : '');
}

$eventsByUserDay = [];
$error = '';
try {
    if ($scopeIds !== []) {
        $placeholders = implode(',', array_fill(0, count($scopeIds), '?'));
        $sql = "
            SELECT r.id_richiesta, r.id_utente_richiedente,
                   p.data_da, p.data_a, p.ora_da, p.ora_a, p.tipo_periodo,
                   te.codice AS codice_tipologia, te.descrizione AS tipologia, te.descrizione_calendario,
                   te.mostra_dettaglio_colleghi, te.mostra_dettaglio_responsabili, te.mostra_dettaglio_hr,
                   sr.codice AS codice_stato_richiesta, sr.descrizione AS stato_richiesta,
                   sp.descrizione_breve AS stato_presenza_breve, sp.descrizione AS stato_presenza,
                   r.oggetto,
                   u.nome, u.cognome, u.username
            FROM hr_richieste r
            INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta=r.id_stato_richiesta
                AND sr.codice IN ('APPROVATA','IN_ATTESA')
            LEFT JOIN hr_richieste_approvazioni ra ON ra.id_richiesta=r.id_richiesta
                AND ra.id_approvatore_assegnato=? AND ra.stato_approvazione='IN_ATTESA'
            INNER JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta
            INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento=r.id_tipologia_evento
                AND te.visibile_calendario=1 AND te.attivo=1
            INNER JOIN hr_stati_presenza sp ON sp.id_stato_presenza=te.id_stato_presenza
            INNER JOIN aut_utenti u ON u.id_utente=r.id_utente_richiedente
            WHERE r.id_utente_richiedente IN ($placeholders)
              AND p.data_da<=? AND p.data_a>=?
              AND (sr.codice='APPROVATA' OR
                  (sr.codice='IN_ATTESA' AND
                   (r.id_utente_richiedente=? OR ?=1 OR ra.id_richiesta_approvazione IS NOT NULL)))
            ORDER BY p.data_da,p.ora_da,u.cognome,u.nome";
        $params = [$idUtente];
        $params = array_merge($params, $scopeIds);
        $params[] = $finePeriodo->format('Y-m-d');
        $params[] = $inizioPeriodo->format('Y-m-d');
        $params[] = $idUtente;
        $params[] = $puoVederePendentiGlobali ? 1 : 0;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $start = new DateTimeImmutable((string)$row['data_da']);
            $end = new DateTimeImmutable((string)$row['data_a']);
            $uid = (int)$row['id_utente_richiedente'];
            $showDetail = hrMostraDettaglioCalendario($row, $scopeMap, $idUtente, $puoConfigurare, $puoVedereTipologieAssenze);
            $label = $showDetail
                ? trim((string)($row['descrizione_calendario'] ?: $row['tipologia'] ?: 'Assenza'))
                : trim((string)($row['stato_presenza_breve'] ?: $row['stato_presenza'] ?: 'Assente'));
            for ($d=$start; $d<=$end; $d=$d->modify('+1 day')) {
                $key=$d->format('Y-m-d');
                if ($key<$inizioPeriodo->format('Y-m-d') || $key>$finePeriodo->format('Y-m-d')) continue;
                $eventsByUserDay[$uid][$key][] = [
                    'id'=>(int)$row['id_richiesta'],
                    'label'=>$label !== '' ? $label : 'Assenza',
                    'codice_tipologia'=>strtoupper(trim((string)($row['codice_tipologia'] ?? ''))),
                    'stato'=>(string)$row['codice_stato_richiesta'],
                    'stato_label'=>(string)$row['stato_richiesta'],
                    'tipo_periodo'=>(string)$row['tipo_periodo'],
                    'ora_da'=>$row['ora_da'] ? substr((string)$row['ora_da'],0,5) : '',
                    'ora_a'=>$row['ora_a'] ? substr((string)$row['ora_a'],0,5) : '',
                    'data_da'=>(string)$row['data_da'],
                    'data_a'=>(string)$row['data_a'],
                    // L'oggetto breve può contenere informazioni operative: lo esponiamo
                    // soltanto quando l'utente ha già diritto a vedere il dettaglio reale.
                    'oggetto'=>$showDetail ? trim((string)($row['oggetto'] ?? '')) : '',
                    'dettaglio'=>$showDetail,
                ];
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

function hrStatoCella(array $events): string
{
    if ($events === []) return 'free';
    foreach ($events as $e) {
        if (($e['stato'] ?? '') === 'IN_ATTESA') return 'pending';
    }

    // Rosso = indisponibilita personale / non disturbare.
    // Azzurro = assenza o impegno di lavoro: il dettaglio chiarisce se e come contattare la persona.
    // ALTRO resta volutamente personale finche HR non riclassifica la richiesta con una tipologia specifica.
    $tipologieLavoro = ['VISITA_CLIENTE', 'VISITA_FORNITORE', 'FORMAZIONE', 'FIERA', 'SMART'];
    $haPersonale = false;
    $haLavoro = false;
    foreach ($events as $e) {
        if (in_array(strtoupper((string)($e['codice_tipologia'] ?? '')), $tipologieLavoro, true)) {
            $haLavoro = true;
        } else {
            $haPersonale = true;
        }
    }

    // In caso di sovrapposizione nello stesso intervallo, il personale prevale:
    // il rosso mantiene il significato prudenziale "non disturbare".
    if ($haPersonale) return 'personal';
    if ($haLavoro) return 'work';
    return 'personal';
}

function hrFormaIndicatoreCella(array $events): string
{
    if ($events === []) return 'day';

    // Se almeno un evento copre la giornata, prevale il simbolo "giornata".
    // La barretta viene usata solo quando gli eventi del giorno sono tutti ad ore.
    foreach ($events as $e) {
        if (strtoupper((string)($e['tipo_periodo'] ?? '')) !== 'ORE') {
            return 'day';
        }
    }

    return 'hours';
}

function hrTitoloCella(array $events): string
{
    if ($events === []) return 'Nessuna assenza registrata';
    $parts=[];
    foreach ($events as $e) {
        $p=(string)$e['label'];
        if (($e['tipo_periodo'] ?? '') === 'ORE' && $e['ora_da'] && $e['ora_a']) $p.=' '.$e['ora_da'].'-'.$e['ora_a'];
        if (trim((string)($e['oggetto'] ?? '')) !== '') $p.=' · '.trim((string)$e['oggetto']);
        if (($e['stato'] ?? '') === 'IN_ATTESA') $p.=' · da approvare';
        $parts[]=$p;
    }
    return implode(' | ', $parts);
}

$giorni=[];
if ($vista === 'mese') {
    for ($d=$inizioPeriodo; $d<=$finePeriodo; $d=$d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) $giorni[]=$d;
    }
} elseif ($vista === 'settimane') {
    for ($d=$inizioPeriodo; $d<=$finePeriodo; $d=$d->modify('+1 day')) {
        if ((int)$d->format('N') <= 5) $giorni[]=$d;
    }
}

// Elenco effettivamente mostrato: per default solo persone con almeno un evento
// nel periodo corrente; con "Vedi tutti" viene ripristinato l'intero ambito.
$utentiVisualizzati = $scopeUtenti;
if (!$mostraTutti) {
    $utentiVisualizzati = array_values(array_filter(
        $scopeUtenti,
        static fn(array $u): bool => !empty($eventsByUserDay[(int)$u['id_utente']] ?? [])
    ));
}

$detailsJson = json_encode($eventsByUserDay, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '{}';
layoutHeader('Calendario assenze');
?>
<style>
.hr-matrix-page{display:flex;flex-direction:column;gap:14px}
.hr-matrix-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:18px 22px}
.hr-matrix-head h1{margin:0 0 4px}.hr-matrix-head .meta{margin:0}
.hr-view-switch{display:flex;gap:6px;flex-wrap:wrap}.hr-view-switch a{min-height:34px;padding:0 12px}
.hr-view-switch a.is-active{background:var(--rav-yellow,#ffd200)!important;color:var(--rav-blue,#0068c9)!important;border-color:var(--rav-blue,#0068c9)!important}
.hr-period-nav{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 16px}
.hr-period-title{font-weight:800;font-size:16px;text-align:center;flex:1}
.hr-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:0 4px}
.hr-legend{display:flex;gap:16px;flex-wrap:wrap;align-items:center;font-size:13px;color:#475569}
.hr-legend span{display:inline-flex;align-items:center;gap:6px}.hr-status-dot{width:14px;height:14px;border-radius:50%;display:inline-block;border:1px solid rgba(15,23,42,.12)}
.hr-empty{padding:26px 18px;text-align:center;color:#475569;background:#fff;border:1px solid #dbe3ec;border-radius:14px;font-weight:600}
.hr-status-free{background:#e9f7ee}.hr-status-pending{background:#ffd84d}.hr-status-personal,.hr-status-absent{background:#e85b5b}.hr-status-work{background:#42a5e8}.hr-status-off{background:#e5e7eb}
.hr-matrix-wrap{overflow:auto;border-radius:14px;border:1px solid #dbe3ec;background:#fff;-webkit-overflow-scrolling:touch}
.hr-matrix{display:grid;min-width:760px;grid-template-columns:160px repeat(var(--cols),minmax(62px,1fr))}
.hr-matrix-cell{min-height:54px;border-right:1px solid #e5eaf0;border-bottom:1px solid #e5eaf0;display:flex;align-items:center;justify-content:center;padding:6px;position:relative;background:#fff}
.hr-matrix-name{justify-content:flex-start;font-weight:700;position:sticky;left:0;z-index:3;background:#fff;white-space:nowrap}
.hr-matrix-name.is-me{background:#f4f8fc;color:#005aa9}
.hr-matrix-header{min-height:58px;position:sticky;top:0;z-index:2;background:#f7f9fc;flex-direction:column;font-size:12px;font-weight:800;color:#334155}
.hr-matrix-header.hr-matrix-name{z-index:4;align-items:flex-start;justify-content:center}
.hr-matrix-header strong{font-size:15px;color:#172033}
.hr-daycell{cursor:pointer}.hr-daycell.is-empty{cursor:default}.hr-daycell:not(.is-empty):hover,.hr-daycell:not(.is-empty):focus-visible{outline:none;box-shadow:inset 0 0 0 2px #0068c9}
.hr-daycell .hr-status-dot{width:20px;height:20px;box-shadow:0 1px 2px rgba(15,23,42,.12)}
.hr-daycell .hr-status-dot.hr-duration-hours{width:20px;height:20px;border-radius:50%;box-sizing:border-box;background-color:#fff;background-image:linear-gradient(to right,currentColor 0,currentColor 50%,transparent 50%,transparent 100%);background-clip:padding-box;border:1px solid rgba(15,23,42,.16)}
.hr-daycell .hr-status-dot.hr-status-pending.hr-duration-hours{color:#ffd84d}
.hr-daycell .hr-status-dot.hr-status-personal.hr-duration-hours,.hr-daycell .hr-status-dot.hr-status-absent.hr-duration-hours{color:#e85b5b}
.hr-daycell .hr-status-dot.hr-status-work.hr-duration-hours{color:#42a5e8}
.hr-daycell.is-today{background:#f7fbff}.hr-daycell.is-today:after{content:"";position:absolute;inset:3px;border:1px solid rgba(0,104,201,.28);border-radius:8px;pointer-events:none}
.hr-day-view{overflow:auto;border:1px solid #dbe3ec;border-radius:14px;background:#fff}
.hr-timeline{min-width:860px;display:grid;grid-template-columns:160px repeat(18,minmax(38px,1fr))}
.hr-time-head{min-height:48px;background:#f7f9fc;font-size:11px;font-weight:700;color:#475569;border-bottom:1px solid #e5eaf0;border-right:1px solid #e5eaf0;display:flex;align-items:flex-start;justify-content:flex-start;padding:8px 0 0 3px}
.hr-time-head:last-child:after{content:"17:00";position:absolute;right:-17px}.hr-time-head{position:relative}
.hr-time-cell{height:48px;border-right:1px solid #edf0f4;border-bottom:1px solid #e5eaf0;background:#e9f7ee;cursor:pointer}.hr-time-cell.is-empty{cursor:default}
.hr-time-cell.is-pending{background:#ffd84d}.hr-time-cell.is-personal,.hr-time-cell.is-absent{background:#e85b5b}.hr-time-cell.is-work{background:#42a5e8}
.hr-time-name{height:48px;display:flex;align-items:center;padding:0 8px;font-weight:700;border-right:1px solid #e5eaf0;border-bottom:1px solid #e5eaf0;position:sticky;left:0;z-index:3;background:#fff;white-space:nowrap}
.hr-detail-pop{position:fixed;z-index:5000;display:none;width:min(360px,calc(100vw - 24px));background:#fff;border:1px solid #ccd7e3;border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.22);padding:14px}
.hr-detail-pop.is-open{display:block}.hr-detail-pop h3{margin:0 28px 8px 0;font-size:16px}.hr-detail-close{position:absolute;right:8px;top:8px;border:0!important;background:transparent!important;color:#475569!important;min-height:28px!important;padding:0 8px!important}
.hr-detail-item{padding:9px 0;border-top:1px solid #edf0f4;font-size:13px}.hr-detail-item:first-of-type{border-top:0}.hr-detail-item strong{display:block;margin-bottom:3px}
@media(max-width:700px){
 .hr-matrix-page{gap:10px}.hr-matrix-head{padding:14px;align-items:stretch;flex-direction:column}
 .hr-view-switch{display:grid;grid-template-columns:repeat(3,1fr)}.hr-view-switch a{padding:0 7px;font-size:12px}
 .hr-period-nav{padding:9px}.hr-period-title{font-size:14px}
 .hr-toolbar{align-items:stretch}.hr-toolbar>.btn{width:100%}.hr-legend{gap:9px;font-size:11px}
 .hr-matrix{min-width:650px;grid-template-columns:112px repeat(var(--cols),minmax(52px,1fr))}
 .hr-matrix-cell{min-height:48px;padding:4px}.hr-matrix-name{font-size:12px}.hr-matrix-header{font-size:10px}.hr-matrix-header strong{font-size:13px}
 .hr-daycell .hr-status-dot{width:18px;height:18px}.hr-daycell .hr-status-dot.hr-duration-hours{width:18px;height:18px}
 .hr-timeline{min-width:760px;grid-template-columns:112px repeat(18,minmax(36px,1fr))}
 .hr-time-name{font-size:12px}
}
</style>

<div class="hr-matrix-page">
<?php renderHrAlert($error, 'danger'); ?>
<section class="card hr-matrix-head">
  <div><h1>Calendario assenze</h1><p class="meta">Disponibilità del tuo gruppo di lavoro. Tocca o clicca un indicatore per il dettaglio.</p></div>
  <nav class="hr-view-switch" aria-label="Vista calendario">
    <?php foreach (['giorno'=>'Giorno','settimane'=>'2 settimane','mese'=>'Mese'] as $k=>$v): ?>
      <a class="btn btn-outline <?= $vista===$k?'is-active':'' ?>" href="?vista=<?= h($k) ?>&data=<?= h($dataRif->format('Y-m-d')) ?><?= h($mostraParam) ?>"><?= h($v) ?></a>
    <?php endforeach; ?>
  </nav>
</section>

<section class="card hr-period-nav">
 <a class="btn btn-outline" href="?vista=<?= h($vista) ?>&data=<?= h($prevData->format('Y-m-d')) ?><?= h($mostraParam) ?>" aria-label="Periodo precedente"><i class="la la-angle-left"></i></a>
 <div class="hr-period-title"><?= h($titoloPeriodo) ?></div>
 <a class="btn btn-outline" href="?vista=<?= h($vista) ?>&data=<?= h($oggi->format('Y-m-d')) ?><?= h($mostraParam) ?>">Oggi</a>
 <a class="btn btn-outline" href="?vista=<?= h($vista) ?>&data=<?= h($nextData->format('Y-m-d')) ?><?= h($mostraParam) ?>" aria-label="Periodo successivo"><i class="la la-angle-right"></i></a>
</section>

<div class="hr-toolbar">
 <div class="hr-legend">
  <span><i class="hr-status-dot hr-status-free"></i>Nessuna assenza</span>
  <span><i class="hr-status-dot hr-status-pending"></i>Da approvare</span>
  <span><i class="hr-status-dot hr-status-personal"></i>Assenza personale</span>
  <span><i class="hr-status-dot hr-status-work"></i>Impegno di lavoro</span>
  <?php if ($vista !== 'giorno'): ?>
  <span title="Forma indicatore"><i class="hr-status-dot hr-status-off"></i>Giornata <i class="hr-status-dot hr-status-off hr-duration-hours" style="width:14px;height:14px;border-radius:50%;box-sizing:border-box;background-color:#fff;background-image:linear-gradient(to right,#e5e7eb 0,#e5e7eb 50%,transparent 50%,transparent 100%);background-clip:padding-box;border:1px solid rgba(15,23,42,.16)"></i>Ore</span>
  <?php endif; ?>
 </div>
 <a class="btn btn-outline" href="?vista=<?= h($vista) ?>&data=<?= h($dataRif->format('Y-m-d')) ?><?= $mostraTutti ? '' : '&mostra=tutti' ?>">
   <?= $mostraTutti ? 'Vedi solo assenze' : 'Vedi tutti' ?>
 </a>
</div>

<?php if ($utentiVisualizzati === []): ?>
<div class="hr-empty">Nessuna assenza o richiesta nel periodo visualizzato.</div>
<?php elseif ($vista === 'giorno'): ?>
<div class="hr-day-view">
 <div class="hr-timeline">
  <div class="hr-time-head hr-matrix-name">Persona</div>
  <?php for($m=8*60;$m<17*60;$m+=30): ?><div class="hr-time-head"><?= h(sprintf('%02d:%02d',intdiv($m,60),$m%60)) ?></div><?php endfor; ?>
  <?php foreach($utentiVisualizzati as $u): $uid=(int)$u['id_utente']; $key=$dataRif->format('Y-m-d'); $evs=$eventsByUserDay[$uid][$key]??[]; ?>
   <div class="hr-time-name <?= $uid===$idUtente?'is-me':'' ?>"><?= h(hrNomeCompatto($u,$idUtente)) ?></div>
   <?php for($m=8*60;$m<17*60;$m+=30):
      $slotEnd=$m+30; $slotEvents=[];
      foreach($evs as $e){
        if(($e['tipo_periodo']??'')!=='ORE'){ $slotEvents[]=$e; continue; }
        [$hh1,$mm1]=array_map('intval',explode(':',$e['ora_da']?:'00:00'));
        [$hh2,$mm2]=array_map('intval',explode(':',$e['ora_a']?:'00:00'));
        $a=$hh1*60+$mm1; $b=$hh2*60+$mm2;
        if($a<$slotEnd && $b>$m) $slotEvents[]=$e;
      }
      $st=hrStatoCella($slotEvents);
   ?><div<?= $slotEvents !== [] ? ' tabindex="0"' : '' ?> class="hr-time-cell<?= $slotEvents === [] ? ' is-empty' : '' ?> <?= $st==='pending'?'is-pending':($st==='work'?'is-work':($st==='personal'?'is-personal':'')) ?>"<?= $slotEvents !== [] ? ' data-user="'.$uid.'" data-day="'.h($key).'" data-slot="'.$m.'" title="'.h(hrTitoloCella($slotEvents)).'"' : '' ?>></div><?php endfor; ?>
  <?php endforeach; ?>
 </div>
</div>
<?php else: ?>
<div class="hr-matrix-wrap">
 <div class="hr-matrix" style="--cols:<?= count($giorni) ?>">
  <div class="hr-matrix-cell hr-matrix-header hr-matrix-name">Persona</div>
  <?php foreach($giorni as $d): ?><div class="hr-matrix-cell hr-matrix-header <?= $d->format('Y-m-d')===$oggi->format('Y-m-d')?'is-today':'' ?>"><span><?= h(hrNomeGiornoBreve($d)) ?></span><strong><?= h($d->format('d/m')) ?></strong></div><?php endforeach; ?>
  <?php foreach($utentiVisualizzati as $u): $uid=(int)$u['id_utente']; ?>
   <div class="hr-matrix-cell hr-matrix-name <?= $uid===$idUtente?'is-me':'' ?>"><?= h(hrNomeCompatto($u,$idUtente)) ?></div>
   <?php foreach($giorni as $d): $key=$d->format('Y-m-d'); $evs=$eventsByUserDay[$uid][$key]??[]; $st=hrStatoCella($evs); $forma=hrFormaIndicatoreCella($evs); ?>
    <div<?= $evs !== [] ? ' tabindex="0" role="button"' : '' ?> class="hr-matrix-cell hr-daycell<?= $evs === [] ? ' is-empty' : '' ?> <?= $key===$oggi->format('Y-m-d')?'is-today':'' ?>"<?= $evs !== [] ? ' data-user="'.$uid.'" data-day="'.h($key).'" title="'.h(hrTitoloCella($evs)).'"' : '' ?>><i class="hr-status-dot hr-status-<?= h($st) ?> hr-duration-<?= h($forma) ?>"></i></div>
   <?php endforeach; ?>
  <?php endforeach; ?>
 </div>
</div>
<?php endif; ?>
</div>

<div class="hr-detail-pop" id="hrDetailPop" role="dialog" aria-modal="false" aria-live="polite">
 <button type="button" class="hr-detail-close" id="hrDetailClose" aria-label="Chiudi">×</button>
 <h3 id="hrDetailTitle">Dettaglio</h3><div id="hrDetailBody"></div>
</div>

<script>
(function(){
 const data=<?= $detailsJson ?>, pop=document.getElementById('hrDetailPop'), title=document.getElementById('hrDetailTitle'), body=document.getElementById('hrDetailBody');
 const names={<?php foreach($utentiVisualizzati as $u): ?><?= (int)$u['id_utente'] ?>:<?= json_encode(hrNomeCompatto($u,$idUtente),JSON_UNESCAPED_UNICODE) ?>,<?php endforeach; ?>};
 function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
 function show(el){
   const uid=el.dataset.user, day=el.dataset.day, all=(data[uid]&&data[uid][day])||[];
   let evs=all;
   if(el.dataset.slot!==undefined){
     const a=Number(el.dataset.slot),b=a+30;
     evs=all.filter(e=>{if(e.tipo_periodo!=='ORE')return true;const x=e.ora_da.split(':').map(Number),y=e.ora_a.split(':').map(Number);return x[0]*60+x[1]<b&&y[0]*60+y[1]>a;});
   }
   title.textContent=(names[uid]||'Persona')+' · '+day.split('-').reverse().join('/');
   body.innerHTML=evs.length?evs.map(e=>'<div class="hr-detail-item"><strong>'+esc(e.label)+'</strong>'+(e.oggetto?'<div><b>Oggetto:</b> '+esc(e.oggetto)+'</div>':'')+'<div>'+esc(e.tipo_periodo==='ORE'&&e.ora_da&&e.ora_a?e.ora_da+' - '+e.ora_a:'Giornata')+(e.stato==='IN_ATTESA'?' · Da approvare':'')+'</div></div>').join(''):'<div class="hr-detail-item"><strong>Nessuna assenza registrata</strong>Disponibile nel periodo selezionato.</div>';
   pop.classList.add('is-open');
   const r=el.getBoundingClientRect(),w=Math.min(360,window.innerWidth-24);
   pop.style.left=Math.max(12,Math.min(window.innerWidth-w-12,r.left))+'px';
   pop.style.top=Math.max(12,Math.min(window.innerHeight-pop.offsetHeight-12,r.bottom+8))+'px';
 }
 document.querySelectorAll('.hr-daycell:not(.is-empty),.hr-time-cell:not(.is-empty)').forEach(el=>{
   el.addEventListener('click',()=>show(el));
   el.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();show(el);}});
 });
 document.getElementById('hrDetailClose').addEventListener('click',()=>pop.classList.remove('is-open'));
 document.addEventListener('click',e=>{if(pop.classList.contains('is-open')&&!pop.contains(e.target)&&!e.target.closest('.hr-daycell,.hr-time-cell'))pop.classList.remove('is-open');});
})();
</script>
<?php layoutFooter(); ?>
