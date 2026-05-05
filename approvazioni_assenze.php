<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/filtri.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/table.php';
require_once __DIR__ . '/includes/badge.php';
require_once __DIR__ . '/includes/actions.php';

richiediPermessoLettura('approvazioni_assenze');

$idUtente = (int)($_SESSION['id_utente'] ?? 0);
$puoScrivere = haPermessoScrittura('approvazioni_assenze');
$puoConfigurare = haPermessoLettura('configurazione_assenze');

$errore = '';
$messaggio = '';

$filtroStato = trim((string)($_GET['stato'] ?? 'IN_ATTESA'));
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



<div class="approvals-page">
    <?php
    renderHrPageHeader([
        'tag' => 'section',
        'class' => 'approvals-hero',
        'title' => 'Approvazioni assenze',
        'subtitle' => "Gestisci le richieste in attesa. L'approvazione può essere confermata senza nota; il rifiuto richiede sempre una motivazione.",
        'icon' => 'la la-check-circle',
        'extra_html' => '<div class="approvals-scope"><i class="la la-user-shield" aria-hidden="true"></i><span>Ambito corrente: <strong>' . h(hrScopeLabelApprovazioni($puoConfigurare)) . '</strong></span></div>',
        'actions' => [
            [
                'href' => 'assenze.php',
                'label' => 'Le mie richieste',
                'icon' => 'la la-calendar',
                'class' => 'btn btn-outline-primary btn-sm',
            ],
        ],
    ]);
    ?>

    <?php if ($messaggio !== ''): ?>
        <div class="alert alert-success"><?= h($messaggio) ?></div>
    <?php endif; ?>

    <?php if ($errore !== ''): ?>
        <div class="alert alert-danger"><?= h($errore) ?></div>
    <?php endif; ?>

    <?php
    renderHrSummaryLine([
        ['value' => (int)$riepilogo['visualizzate'], 'label' => 'richieste visualizzate'],
        ['value' => (int)$riepilogo['pendenti'], 'label' => 'da gestire'],
        ['value' => (int)$riepilogo['approvate_oggi'], 'label' => 'approvate oggi'],
        ['value' => (int)$riepilogo['rifiutate_oggi'], 'label' => 'rifiutate oggi'],
    ], 'approvals-summary', 'Riepilogo approvazioni assenze');
    ?>

    <?php
    renderHrFiltri([
        'action' => 'approvazioni_assenze.php',
        'method' => 'get',
        'active' => $filtriAttivi,
        'fields' => [
            [
                'name' => 'stato',
                'label' => 'Stato',
                'type' => 'select',
                'value' => $filtroStato,
                'options' => [
                    [ 'value' => '', 'label' => 'Tutti gli stati' ],
                    [ 'value' => 'IN_ATTESA', 'label' => 'Pendenti' ],
                    [ 'value' => 'APPROVATA', 'label' => 'Approvate' ],
                    [ 'value' => 'RIFIUTATA', 'label' => 'Rifiutate' ],
                ],
            ],
            [
                'name' => 'data_da',
                'label' => 'Dal',
                'type' => 'date',
                'value' => $filtroDataDa,
            ],
            [
                'name' => 'data_a',
                'label' => 'Al',
                'type' => 'date',
                'value' => $filtroDataA,
            ],
            [
                'name' => 'tipologia',
                'label' => 'Tipologia',
                'type' => 'select',
                'value' => $filtroTipologia,
                'options' => array_merge(
                    [[ 'value' => '', 'label' => 'Tutte' ]],
                    array_map(static function (array $tipologia): array {
                        return [
                            'value' => (string)$tipologia['codice'],
                            'label' => (string)$tipologia['descrizione'],
                        ];
                    }, $tipologie)
                ),
            ],
            [
                'name' => 'utente',
                'label' => 'Richiedente',
                'type' => 'text',
                'value' => $filtroUtente,
                'placeholder' => 'Nome o cognome',
            ],
        ],
        'reset_url' => 'approvazioni_assenze.php',
    ]);
    ?>
    <?php
    renderHrTableSection([
        'title' => 'Richieste',
        'subtitle' => 'Sono mostrate solo le informazioni utili alla decisione.',
        'rows' => $richieste,
        'columns' => [
            ['label' => 'Richiedente'],
            ['label' => 'Richiesta'],
            ['label' => 'Periodo'],
            ['label' => 'Stato'],
            ['label' => 'Note'],
            ['label' => 'Decisione', 'style' => 'width: 320px;'],
        ],
        'empty_message' => 'Nessuna richiesta trovata con i filtri impostati.',
        'section_class' => 'card approvals-table-card',
        'title_class' => 'approvals-table-title',
        'responsive_class' => 'table-responsive',
        'table_class' => 'table',
        'row_renderer' => static function (array $richiesta) use ($puoConfigurare, $puoScrivere): void {
            $stato = (string)$richiesta['stato_approvazione'];
            ?>
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
                    <?= renderHrStatusBadge($stato) ?>
                    <?php if ((int)$richiesta['gestita_da_hr'] === 1): ?>
                        <br><?= renderHrStatusBadge('IN_ATTESA', 'gestita da HR', ['style' => 'margin-top: 0.35rem;']) ?>
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
                                <?= renderHrPrimaryActionButton('Approva', 'la la-check', !$puoScrivere) ?>
                            </form>

                            <form method="post" class="approvals-action-form">
                                <input type="hidden" name="azione" value="rifiuta_richiesta">
                                <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                <input type="text" name="nota_approvatore" placeholder="Motivo rifiuto obbligatorio" aria-label="Motivo rifiuto obbligatorio" required>
                                <?= renderHrDangerOutlineActionButton('Rifiuta', 'la la-times', !$puoScrivere) ?>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="text-muted">Già gestita</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        },
    ]);
    ?>
</div>

<?php layoutFooter(); ?>
