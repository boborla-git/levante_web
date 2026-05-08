<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('approvazioni_assenze');

$idUtente = (int)($_SESSION['id_utente'] ?? 0);
$puoScrivere = haPermessoScrittura('approvazioni_assenze');
$puoConfigurare = haPermessoLettura('configurazione_assenze');

$errore = '';
$messaggio = '';

$filtroStato = trim((string)($_GET['stato'] ?? ''));
$filtroDataDa = trim((string)($_GET['data_da'] ?? ''));
$filtroDataA = trim((string)($_GET['data_a'] ?? ''));
$filtroTipologia = trim((string)($_GET['tipologia'] ?? ''));
$filtroUtente = trim((string)($_GET['utente'] ?? ''));

$filtriAttivi = $filtroStato !== 'IN_ATTESA'
    || $filtroDataDa !== ''
    || $filtroDataA !== ''
    || $filtroTipologia !== ''
    || $filtroUtente !== '';

$riepilogo = [
    'visualizzate' => 0,
    'pendenti' => 0,
    'approvate_oggi' => 0,
    'rifiutate_oggi' => 0,
];

$richieste = [];
$tipologie = [];

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function hrScopeLabelApprovazioni(bool $puoConfigurare): string
{
    return $puoConfigurare ? 'tutte le richieste' : 'solo le richieste assegnate a te';
}

function hrIdStatoRichiesta(PDO $pdo, string $codice): int
{
    static $cache = [];

    if (isset($cache[$codice])) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare("\n        SELECT id_stato_richiesta\n        FROM hr_stati_richiesta\n        WHERE codice = :codice\n        LIMIT 1\n    ");
    $stmt->execute(['codice' => $codice]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('Stato richiesta non trovato: ' . $codice);
    }

    $cache[$codice] = (int)$id;
    return $cache[$codice];
}

function hrIdCanaleNotifica(PDO $pdo, string $codice): ?int
{
    static $cache = [];

    if (array_key_exists($codice, $cache)) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare("\n        SELECT id_canale_notifica\n        FROM hr_canali_notifica\n        WHERE codice = :codice\n          AND attivo = 1\n        LIMIT 1\n    ");
    $stmt->execute(['codice' => $codice]);
    $id = $stmt->fetchColumn();

    $cache[$codice] = $id === false ? null : (int)$id;
    return $cache[$codice];
}

function hrCreaNotificaWeb(
    PDO $pdo,
    string $tipoEvento,
    string $titolo,
    string $messaggio,
    ?string $link,
    ?int $idRichiesta,
    ?int $creatoDa,
    array $destinatari
): void {
    $destinatari = array_values(array_unique(array_filter(
        array_map('intval', $destinatari),
        static fn (int $v): bool => $v > 0
    )));

    if (count($destinatari) === 0) {
        return;
    }

    $idCanaleWeb = hrIdCanaleNotifica($pdo, 'WEB');
    if ($idCanaleWeb === null) {
        return;
    }

    $stmt = $pdo->prepare("\n        INSERT INTO hr_notifiche\n            (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da)\n        VALUES\n            (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)\n    ");
    $stmt->execute([
        'tipo_evento' => $tipoEvento,
        'titolo' => $titolo,
        'messaggio' => $messaggio,
        'link' => $link,
        'id_richiesta' => $idRichiesta,
        'creato_da' => $creatoDa,
    ]);

    $idNotifica = (int)$pdo->lastInsertId();

    $stmtDest = $pdo->prepare("\n        INSERT INTO hr_notifiche_destinatari\n            (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio)\n        VALUES\n            (:id_notifica, :id_utente, :id_canale_notifica, 1, 0, NOW())\n    ");

    foreach ($destinatari as $idUtenteDest) {
        $stmtDest->execute([
            'id_notifica' => $idNotifica,
            'id_utente' => $idUtenteDest,
            'id_canale_notifica' => $idCanaleWeb,
        ]);
    }
}

function hrPeriodoRichiesta(array $r): string
{
    $dataDa = trim((string)($r['data_da'] ?? ''));
    $dataA = trim((string)($r['data_a'] ?? ''));
    $oraDa = trim((string)($r['ora_da'] ?? ''));
    $oraA = trim((string)($r['ora_a'] ?? ''));
    $tipoPeriodo = strtoupper(trim((string)($r['tipo_periodo'] ?? '')));

    if ($dataDa === '' && $dataA === '') {
        return 'Periodo non disponibile';
    }

    $periodo = $dataDa;
    if ($dataA !== '' && $dataA !== $dataDa) {
        $periodo .= ' - ' . $dataA;
    }

    if ($tipoPeriodo === 'ORE' && $oraDa !== '' && $oraA !== '') {
        $periodo .= ' · ' . $oraDa . ' - ' . $oraA;
    } elseif ($tipoPeriodo === 'GIORNI') {
        $periodo .= ' · giornata intera';
    }

    return $periodo;
}

function hrClasseEsito(string $stato): string
{
    if ($stato === 'APPROVATA') {
        return 'badge-success';
    }

    if ($stato === 'RIFIUTATA') {
        return 'badge-danger';
    }

    return 'badge-warning';
}

function hrDescrizioneStato(string $stato): string
{
    return match ($stato) {
        'IN_ATTESA' => 'In attesa',
        'APPROVATA' => 'Approvata',
        'RIFIUTATA' => 'Rifiutata',
        default => $stato,
    };
}

try {
    $filtroApprovatore = $puoConfigurare
        ? '1 = 1'
        : 'a.id_approvatore_assegnato = :id_utente';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            http_response_code(403);
            die('Accesso negato.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));
        if (!in_array($azione, ['approva_richiesta', 'rifiuta_richiesta'], true)) {
            throw new RuntimeException('Azione non valida.');
        }

        $idRichiesta = (int)($_POST['id_richiesta'] ?? 0);
        $notaApprovatore = trim((string)($_POST['nota_approvatore'] ?? ''));

        if ($idRichiesta <= 0) {
            throw new RuntimeException('Richiesta non valida.');
        }

        if ($azione === 'rifiuta_richiesta' && $notaApprovatore === '') {
            throw new RuntimeException('Per rifiutare una richiesta devi indicare una motivazione.');
        }

        $filtroPost = $puoConfigurare ? '' : ' AND a.id_approvatore_assegnato = :id_utente ';
        $stmtRichiesta = $pdo->prepare("\n            SELECT\n                r.id_richiesta,\n                r.id_utente_richiedente,\n                a.id_richiesta_approvazione,\n                a.id_approvatore_assegnato,\n                te.descrizione AS tipologia,\n                CONCAT(COALESCE(au.nome, ''), ' ', COALESCE(au.cognome, '')) AS richiedente_nome\n            FROM hr_richieste r\n            INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta\n            INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta\n            INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento\n            INNER JOIN aut_utenti au ON au.id_utente = r.id_utente_richiedente\n            WHERE r.id_richiesta = :id_richiesta\n              AND sr.codice = 'IN_ATTESA'\n              AND a.stato_approvazione = 'IN_ATTESA'\n              {$filtroPost}\n            ORDER BY a.livello_approvazione ASC, a.id_richiesta_approvazione ASC\n            LIMIT 1\n        ");

        $paramsRichiesta = ['id_richiesta' => $idRichiesta];
        if (!$puoConfigurare) {
            $paramsRichiesta['id_utente'] = $idUtente;
        }

        $stmtRichiesta->execute($paramsRichiesta);
        $richiesta = $stmtRichiesta->fetch(PDO::FETCH_ASSOC);

        if (!$richiesta) {
            throw new RuntimeException('Richiesta non trovata, già gestita oppure fuori dal tuo perimetro di approvazione.');
        }

        $gestioneHr = $puoConfigurare && (int)$richiesta['id_approvatore_assegnato'] !== $idUtente;
        $codiceStato = $azione === 'approva_richiesta' ? 'APPROVATA' : 'RIFIUTATA';
        $idStato = hrIdStatoRichiesta($pdo, $codiceStato);
        $azioneStorico = $azione === 'approva_richiesta' ? 'APPROVAZIONE' : 'RIFIUTO';

        $pdo->beginTransaction();

        $stmtUpdApp = $pdo->prepare("\n            UPDATE hr_richieste_approvazioni\n            SET stato_approvazione = :stato_approvazione,\n                data_risposta = NOW(),\n                esito = :esito,\n                note_approvatore = :note_approvatore,\n                gestita_da_hr = :gestita_da_hr\n            WHERE id_richiesta_approvazione = :id_richiesta_approvazione\n        ");
        $stmtUpdApp->execute([
            'stato_approvazione' => $codiceStato,
            'esito' => $codiceStato,
            'note_approvatore' => $notaApprovatore !== '' ? $notaApprovatore : null,
            'gestita_da_hr' => $gestioneHr ? 1 : 0,
            'id_richiesta_approvazione' => (int)$richiesta['id_richiesta_approvazione'],
        ]);

        $stmtUpdRich = $pdo->prepare("\n            UPDATE hr_richieste\n            SET id_stato_richiesta = :id_stato_richiesta,\n                data_chiusura = NOW(),\n                data_aggiornamento = NOW()\n            WHERE id_richiesta = :id_richiesta\n        ");
        $stmtUpdRich->execute([
            'id_stato_richiesta' => $idStato,
            'id_richiesta' => $idRichiesta,
        ]);

        $dettagliStorico = $notaApprovatore !== '' ? $notaApprovatore : 'Richiesta approvata.';
        if ($gestioneHr) {
            $dettagliStorico = ($azione === 'approva_richiesta'
                ? 'Richiesta approvata da HR/configurazione.'
                : 'Richiesta rifiutata da HR/configurazione.')
                . ($notaApprovatore !== '' ? ' Nota: ' . $notaApprovatore : '');
        }

        $stmtStorico = $pdo->prepare("\n            INSERT INTO hr_richieste_storico\n                (id_richiesta, azione, id_utente_azione, dettagli, origine)\n            VALUES\n                (:id_richiesta, :azione, :id_utente_azione, :dettagli, :origine)\n        ");
        $stmtStorico->execute([
            'id_richiesta' => $idRichiesta,
            'azione' => $azioneStorico,
            'id_utente_azione' => $idUtente,
            'dettagli' => $dettagliStorico,
            'origine' => 'web',
        ]);

        $tipo = trim((string)$richiesta['tipologia']);
        $testoNotifica = $azione === 'approva_richiesta'
            ? 'La tua richiesta di ' . $tipo . ' è stata approvata.'
            : 'La tua richiesta di ' . $tipo . ' è stata rifiutata. Motivo: ' . $notaApprovatore;

        hrCreaNotificaWeb(
            $pdo,
            $azione === 'approva_richiesta' ? 'RICHIESTA_ASSENZA_APPROVATA' : 'RICHIESTA_ASSENZA_RIFIUTATA',
            $azione === 'approva_richiesta' ? 'Richiesta approvata' : 'Richiesta rifiutata',
            $testoNotifica,
            '/assenze.php',
            $idRichiesta,
            $idUtente,
            [(int)$richiesta['id_utente_richiedente']]
        );

        $pdo->commit();

        header('Location: approvazioni_assenze.php?' . ($azione === 'approva_richiesta' ? 'approvata=1' : 'rifiutata=1'));
        exit;
    }

    if (isset($_GET['approvata']) && $_GET['approvata'] === '1') {
        $messaggio = 'Richiesta approvata correttamente.';
    } elseif (isset($_GET['rifiutata']) && $_GET['rifiutata'] === '1') {
        $messaggio = 'Richiesta rifiutata correttamente.';
    }

    $stmtTipologie = $pdo->query("\n        SELECT codice, descrizione\n        FROM hr_tipologie_evento\n        WHERE attivo = 1\n        ORDER BY ordinamento, descrizione\n    ");
    $tipologie = $stmtTipologie->fetchAll(PDO::FETCH_ASSOC);

    $stmtRiepilogo = $pdo->prepare("\n        SELECT\n            SUM(CASE WHEN a.stato_approvazione = 'IN_ATTESA' THEN 1 ELSE 0 END) AS pendenti,\n            SUM(CASE WHEN a.stato_approvazione = 'APPROVATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS approvate_oggi,\n            SUM(CASE WHEN a.stato_approvazione = 'RIFIUTATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS rifiutate_oggi\n        FROM hr_richieste_approvazioni a\n        WHERE {$filtroApprovatore}\n    ");
    $stmtRiepilogo->execute($puoConfigurare ? [] : ['id_utente' => $idUtente]);
    $r = $stmtRiepilogo->fetch(PDO::FETCH_ASSOC);

    if ($r) {
        $riepilogo['pendenti'] = (int)($r['pendenti'] ?? 0);
        $riepilogo['approvate_oggi'] = (int)($r['approvate_oggi'] ?? 0);
        $riepilogo['rifiutate_oggi'] = (int)($r['rifiutate_oggi'] ?? 0);
    }

    $whereExtra = [];
    $paramsExtra = [];

    if ($filtroStato !== '') {
        $whereExtra[] = 'a.stato_approvazione = :filtro_stato';
        $paramsExtra['filtro_stato'] = $filtroStato;
    }

    if ($filtroDataDa !== '') {
        $whereExtra[] = 'COALESCE(p.data_da, DATE(a.data_assegnazione)) >= :data_da';
        $paramsExtra['data_da'] = $filtroDataDa;
    }

    if ($filtroDataA !== '') {
        $whereExtra[] = 'COALESCE(p.data_a, DATE(a.data_assegnazione)) <= :data_a';
        $paramsExtra['data_a'] = $filtroDataA;
    }

    if ($filtroTipologia !== '') {
        $whereExtra[] = 'te.codice = :tipologia';
        $paramsExtra['tipologia'] = $filtroTipologia;
    }

    if ($filtroUtente !== '') {
        $whereExtra[] = "(u.nome LIKE :utente OR u.cognome LIKE :utente OR CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, '')) LIKE :utente)";
        $paramsExtra['utente'] = '%' . $filtroUtente . '%';
    }

    $sqlRichieste = "\n        SELECT\n            r.id_richiesta,\n            r.oggetto,\n            r.note_richiedente,\n            te.descrizione AS tipologia,\n            te.codice AS codice_tipologia,\n            CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, '')) AS richiedente,\n            CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, '')) AS approvatore_assegnato,\n            DATE_FORMAT(MIN(p.data_da), '%d/%m/%Y') AS data_da,\n            DATE_FORMAT(MAX(p.data_a), '%d/%m/%Y') AS data_a,\n            TIME_FORMAT(MIN(p.ora_da), '%H:%i') AS ora_da,\n            TIME_FORMAT(MAX(p.ora_a), '%H:%i') AS ora_a,\n            MIN(p.tipo_periodo) AS tipo_periodo,\n            a.stato_approvazione,\n            DATE_FORMAT(a.data_assegnazione, '%d/%m/%Y %H:%i') AS data_assegnazione,\n            DATE_FORMAT(a.data_risposta, '%d/%m/%Y %H:%i') AS data_risposta,\n            a.note_approvatore,\n            a.gestita_da_hr\n        FROM hr_richieste r\n        INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta\n        INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento\n        INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente\n        LEFT JOIN aut_utenti ua ON ua.id_utente = a.id_approvatore_assegnato\n        LEFT JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta\n        WHERE {$filtroApprovatore}\n    ";

    if (!empty($whereExtra)) {
        $sqlRichieste .= ' AND ' . implode(' AND ', $whereExtra);
    }

    $sqlRichieste .= "\n        GROUP BY\n            r.id_richiesta,\n            r.oggetto,\n            r.note_richiedente,\n            te.descrizione,\n            te.codice,\n            richiedente,\n            approvatore_assegnato,\n            a.stato_approvazione,\n            a.data_assegnazione,\n            a.data_risposta,\n            a.note_approvatore,\n            a.gestita_da_hr\n        ORDER BY\n            CASE a.stato_approvazione\n                WHEN 'IN_ATTESA' THEN 1\n                WHEN 'APPROVATA' THEN 2\n                WHEN 'RIFIUTATA' THEN 3\n                ELSE 9\n            END,\n            COALESCE(a.data_risposta, a.data_assegnazione) DESC,\n            r.id_richiesta DESC\n        LIMIT 200\n    ";

    $params = $puoConfigurare ? [] : ['id_utente' => $idUtente];
    $params = array_merge($params, $paramsExtra);

    $stmtRichieste = $pdo->prepare($sqlRichieste);
    $stmtRichieste->execute($params);
    $richieste = $stmtRichieste->fetchAll(PDO::FETCH_ASSOC);
    $riepilogo['visualizzate'] = count($richieste);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

layoutHeader('Approvazioni assenze');
?>

<style>
.approvals-page {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.approvals-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.15rem;
}

.approvals-hero h1 {
    margin-bottom: 0.35rem;
}

.approvals-scope {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 0.55rem;
    padding: 0.35rem 0.65rem;
    border: 1px solid var(--border-color, #d9e2ec);
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
    color: var(--text-muted, #64748b);
    font-size: 0.9rem;
}

.approvals-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.6rem 1rem;
    padding: 0.85rem 1rem;
    border: 1px solid var(--border-color, #d9e2ec);
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
}

.approvals-summary span {
    display: inline-flex;
    align-items: baseline;
    gap: 0.35rem;
    white-space: nowrap;
    color: var(--text-muted, #64748b);
    font-size: 0.92rem;
}

.approvals-summary strong {
    color: var(--text-color, #172033);
    font-size: 1.08rem;
}

.approvals-filters {
    padding: 0.95rem 1rem;
}

.approvals-filters-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.approvals-filters-title {
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-weight: 700;
}

.approvals-filter-grid {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) minmax(130px, 0.8fr) minmax(130px, 0.8fr) minmax(160px, 1fr) minmax(180px, 1.1fr) auto;
    gap: 0.65rem;
    align-items: end;
}

.approvals-filter-grid label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin: 0;
    font-size: 0.86rem;
    color: var(--text-muted, #64748b);
}

.approvals-filter-grid select,
.approvals-filter-grid input {
    width: 100%;
    min-height: 36px;
}

.approvals-filter-actions {
    display: flex;
    gap: 0.45rem;
    justify-content: flex-end;
    align-items: center;
    white-space: nowrap;
}

.approvals-table-card {
    padding: 1rem;
}

.approvals-table-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.approvals-table-title h2 {
    margin-bottom: 0.2rem;
}


@media (max-width: 760px) {
    .approvals-table-title {
        flex-direction: column;
        align-items: stretch;
    }
}

.approvals-actions {
    display: grid;
    gap: 0.45rem;
    min-width: 290px;
}

.approvals-action-form {
    display: grid;
    grid-template-columns: minmax(150px, 1fr) auto;
    gap: 0.45rem;
    margin: 0;
    align-items: center;
}

.approvals-action-form input {
    min-width: 0;
    width: 100%;
}

@media (max-width: 1100px) {
    .approvals-filter-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .approvals-filter-actions {
        justify-content: flex-start;
    }
}

@media (max-width: 760px) {
    .approvals-hero {
        flex-direction: column;
    }

    .approvals-summary {
        gap: 0.45rem 0.75rem;
    }

    .approvals-summary span {
        white-space: normal;
    }

    .approvals-filter-grid {
        grid-template-columns: 1fr;
    }

    .approvals-filter-actions,
    .approvals-action-form {
        display: grid;
        grid-template-columns: 1fr;
    }

    
@media (max-width: 760px) {
    .approvals-table-title {
        flex-direction: column;
        align-items: stretch;
    }
}

.approvals-actions {
        min-width: 0;
    }
}

.hr-filter-toolbar {
    display: flex;
    align-items: flex-end;
    justify-content: flex-end;
    gap: 1rem;
    margin: 0 0 0.75rem;
}
.hr-filter-search-group {
    width: min(380px, 100%);
}
.hr-filter-search-group input {
    width: 100%;
    min-height: 38px;
    border: 1px solid var(--border-color, #cbd5e1);
    border-radius: 8px;
    padding: 0.55rem 0.75rem;
    font: inherit;
    background: #fff;
}
.hr-filter-search-group input:focus {
    outline: none;
    border-color: var(--primary, #005bd4);
    box-shadow: 0 0 0 2px rgba(0, 91, 212, 0.16);
}
.quick-filter-empty {
    display: none;
    margin-top: 0.75rem;
}
@media (max-width: 760px) {
    .hr-filter-toolbar {
        justify-content: stretch;
    }
    .hr-filter-search-group {
        width: 100%;
    }
}

</style>

<div class="approvals-page">
    <section class="approvals-hero">
        <div>
            <h1><i class="la la-check-circle"></i> Approvazioni assenze</h1>
            <p class="text-muted">
                Gestisci le richieste in attesa. L'approvazione può essere confermata senza nota; il rifiuto richiede sempre una motivazione.
            </p>
            <div class="approvals-scope">
                <i class="la la-user-shield"></i>
                <span>Ambito corrente: <strong><?= h(hrScopeLabelApprovazioni($puoConfigurare)) ?></strong></span>
            </div>
        </div>

        <a href="assenze.php" class="btn btn-outline-primary btn-sm">
            <i class="la la-calendar"></i> Le mie richieste
        </a>
    </section>

    <?php if ($messaggio !== ''): ?>
        <div class="alert alert-success"><?= h($messaggio) ?></div>
    <?php endif; ?>

    <?php if ($errore !== ''): ?>
        <div class="alert alert-danger"><?= h($errore) ?></div>
    <?php endif; ?>

    <section class="approvals-summary" aria-label="Riepilogo approvazioni assenze">
        <span><strong><?= (int)$riepilogo['visualizzate'] ?></strong> richieste visualizzate</span>
        <span><strong><?= (int)$riepilogo['pendenti'] ?></strong> da gestire</span>
        <span><strong><?= (int)$riepilogo['approvate_oggi'] ?></strong> approvate oggi</span>
        <span><strong><?= (int)$riepilogo['rifiutate_oggi'] ?></strong> rifiutate oggi</span>
    </section>

    <section class="card approvals-table-card">
        <div class="approvals-table-title">
            <div>
                <h2>Richieste</h2>
                <p class="text-muted">Sono mostrate solo le informazioni utili alla decisione.</p>
            </div>
            <div class="hr-filter-toolbar">
                <div class="form-group hr-filter-search-group">
                    <label for="approvazioniSearch">Filtro rapido</label>
                    <input type="search" id="approvazioniSearch" data-quick-filter="approvazioniTable" placeholder="Cerca in tutte le colonne..." autocomplete="off">
                </div>
            </div>
        </div>

        <?php if (count($richieste) === 0): ?>
            <p class="text-muted">Nessuna richiesta trovata con i filtri impostati.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table" id="approvazioniTable" data-quick-filter-table>
                    <thead>
                        <tr>
                            <th>Richiedente</th>
                            <th>Richiesta</th>
                            <th>Periodo</th>
                            <th>Stato</th>
                            <th>Note</th>
                            <th style="width: 320px;">Decisione</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($richieste as $richiesta): ?>
                            <?php $stato = (string)$richiesta['stato_approvazione']; ?>
                            <tr>
                                <td>
                                    <strong><?= h(trim((string)$richiesta['richiedente'])) ?></strong>
                                    <?php if ($puoConfigurare && trim((string)$richiesta['approvatore_assegnato']) !== ''): ?>
                                        <br><span class="text-muted">Assegnata a <?= h(trim((string)$richiesta['approvatore_assegnato'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ($stato === 'IN_ATTESA'): ?>
                                        <br><span class="text-muted">Dal <?= h((string)$richiesta['data_assegnazione']) ?></span>
                                    <?php elseif (trim((string)$richiesta['data_risposta']) !== ''): ?>
                                        <br><span class="text-muted">Risposta: <?= h((string)$richiesta['data_risposta']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= h((string)$richiesta['tipologia']) ?></strong>
                                    <?php if (trim((string)$richiesta['oggetto']) !== ''): ?>
                                        <br><?= h((string)$richiesta['oggetto']) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(hrPeriodoRichiesta($richiesta)) ?></td>
                                <td>
                                    <span class="badge <?= h(hrClasseEsito($stato)) ?>">
                                        <?= h(hrDescrizioneStato($stato)) ?>
                                    </span>
                                    <?php if ((int)$richiesta['gestita_da_hr'] === 1): ?>
                                        <br><span class="badge badge-warning" style="margin-top: 0.35rem;">gestita da HR</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stato === 'IN_ATTESA'): ?>
                                        <?php if (trim((string)$richiesta['note_richiedente']) !== ''): ?>
                                            <?= nl2br(h((string)$richiesta['note_richiedente'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Nessuna nota</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if (trim((string)$richiesta['note_approvatore']) !== ''): ?>
                                            <?= nl2br(h((string)$richiesta['note_approvatore'])) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Nessuna nota</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stato === 'IN_ATTESA'): ?>
                                        <div class="approvals-actions">
                                            <form method="post" class="approvals-action-form">
                                                <input type="hidden" name="azione" value="approva_richiesta">
                                                <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                                <input type="text" name="nota_approvatore" placeholder="Nota opzionale" aria-label="Nota opzionale per approvazione">
                                                <button type="submit" class="btn btn-sm btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>
                                                    <i class="la la-check"></i> Approva
                                                </button>
                                            </form>

                                            <form method="post" class="approvals-action-form">
                                                <input type="hidden" name="azione" value="rifiuta_richiesta">
                                                <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                                <input type="text" name="nota_approvatore" placeholder="Motivo rifiuto obbligatorio" aria-label="Motivo rifiuto obbligatorio" required>
                                                <button type="submit" class="btn btn-sm btn-outline-danger" <?= $puoScrivere ? '' : 'disabled' ?>>
                                                    <i class="la la-times"></i> Rifiuta
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">Già gestita</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-muted quick-filter-empty" data-quick-filter-empty="approvazioniTable">Nessuna richiesta corrisponde al filtro rapido.</div>
            </div>
        <?php endif; ?>
    </section>
</div>


<script>
(function () {
    function normalizzaTesto(valore) {
        return (valore || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    document.querySelectorAll('[data-quick-filter]').forEach(function (input) {
        var tableId = input.getAttribute('data-quick-filter');
        var table = document.getElementById(tableId);
        if (!table) { return; }
        var empty = document.querySelector('[data-quick-filter-empty="' + tableId + '"]');
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));

        input.addEventListener('input', function () {
            var query = normalizzaTesto(input.value.trim());
            var visible = 0;
            rows.forEach(function (row) {
                var match = query === '' || normalizzaTesto(row.textContent).indexOf(query) !== -1;
                row.style.display = match ? '' : 'none';
                if (match) { visible += 1; }
            });
            if (empty) {
                empty.style.display = visible === 0 ? 'block' : 'none';
            }
        });
    });
})();
</script>

<?php layoutFooter(); ?>
