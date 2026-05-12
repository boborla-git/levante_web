<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/hr_notifiche.php';
require_once __DIR__ . '/includes/ui.php';
require_once __DIR__ . '/includes/badge.php';

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

$redirectFiltri = http_build_query(array_filter([
    'stato' => $filtroStato !== 'IN_ATTESA' ? $filtroStato : null,
    'data_da' => $filtroDataDa !== '' ? $filtroDataDa : null,
    'data_a' => $filtroDataA !== '' ? $filtroDataA : null,
    'tipologia' => $filtroTipologia !== '' ? $filtroTipologia : null,
    'utente' => $filtroUtente !== '' ? $filtroUtente : null,
], static fn ($v): bool => $v !== null));

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

function selectedAttr(string $valore, string $corrente): string
{
    return $valore === $corrente ? ' selected' : '';
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

function hrRedirectApprovazioniConFiltri(string $queryFiltri, string $esito): string
{
    parse_str($queryFiltri, $params);

    $ammessi = ['stato', 'data_da', 'data_a', 'tipologia', 'utente'];
    $puliti = [];

    foreach ($ammessi as $chiave) {
        if (array_key_exists($chiave, $params) && is_scalar($params[$chiave])) {
            $puliti[$chiave] = trim((string)$params[$chiave]);
        }
    }

    $puliti[$esito] = '1';
    $query = http_build_query($puliti);

    return 'approvazioni_assenze.php' . ($query !== '' ? '?' . $query : '');
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

        $redirectQuery = trim((string)($_POST['redirect_query'] ?? ''));
        header('Location: ' . hrRedirectApprovazioniConFiltri(
            $redirectQuery,
            $azione === 'approva_richiesta' ? 'approvata' : 'rifiutata'
        ));
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

    <?php renderHrAlert($messaggio, 'success'); ?>
    <?php renderHrAlert($errore, 'danger'); ?>

    <section class="approvals-summary" aria-label="Riepilogo approvazioni assenze">
        <span><strong><?= (int)$riepilogo['visualizzate'] ?></strong> richieste visualizzate</span>
        <span><strong><?= (int)$riepilogo['pendenti'] ?></strong> da gestire</span>
        <span><strong><?= (int)$riepilogo['approvate_oggi'] ?></strong> approvate oggi</span>
        <span><strong><?= (int)$riepilogo['rifiutate_oggi'] ?></strong> rifiutate oggi</span>
    </section>

    <section class="card approvals-filters" aria-label="Filtri approvazioni assenze">
        <div class="approvals-filters-header">
            <div class="approvals-filters-title">
                <i class="la la-filter"></i>
                <span>Filtri</span>
                <?php if ($filtriAttivi): ?>
                    <?= renderHrStatusBadge('IN_ATTESA', 'filtri attivi') ?>
                <?php endif; ?>
            </div>
            <a href="approvazioni_assenze.php" class="btn btn-sm btn-outline-secondary">
                <i class="la la-undo"></i> Ripristina
            </a>
        </div>

        <form method="get" class="approvals-filter-grid">
            <label>
                Stato
                <select name="stato">
                    <option value=""<?= selectedAttr('', $filtroStato) ?>>Tutti</option>
                    <option value="IN_ATTESA"<?= selectedAttr('IN_ATTESA', $filtroStato) ?>>In attesa</option>
                    <option value="APPROVATA"<?= selectedAttr('APPROVATA', $filtroStato) ?>>Approvate</option>
                    <option value="RIFIUTATA"<?= selectedAttr('RIFIUTATA', $filtroStato) ?>>Rifiutate</option>
                </select>
            </label>

            <label>
                Dal giorno
                <input type="date" name="data_da" value="<?= h($filtroDataDa) ?>">
            </label>

            <label>
                Al giorno
                <input type="date" name="data_a" value="<?= h($filtroDataA) ?>">
            </label>

            <label>
                Tipologia
                <select name="tipologia">
                    <option value=""<?= selectedAttr('', $filtroTipologia) ?>>Tutte</option>
                    <?php foreach ($tipologie as $tipologia): ?>
                        <option value="<?= h((string)$tipologia['codice']) ?>"<?= selectedAttr((string)$tipologia['codice'], $filtroTipologia) ?>>
                            <?= h((string)$tipologia['descrizione']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Richiedente
                <input type="search" name="utente" value="<?= h($filtroUtente) ?>" placeholder="Nome o cognome" autocomplete="off">
            </label>

            <div class="approvals-filter-actions">
                <button type="submit" class="btn btn-sm btn-primary">
                    <i class="la la-search"></i> Applica
                </button>
            </div>
        </form>
    </section>

    <section class="card approvals-table-card">
        <div class="approvals-table-title">
            <div>
                <h2>Richieste</h2>
                <p class="text-muted">Sono mostrate le richieste coerenti con i filtri impostati; il filtro rapido cerca nella tabella già caricata.</p>
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
                                                <input type="hidden" name="redirect_query" value="<?= h($redirectFiltri) ?>">
                                                <input type="text" name="nota_approvatore" placeholder="Nota opzionale" aria-label="Nota opzionale per approvazione">
                                                <button type="submit" class="btn btn-sm btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>
                                                    <i class="la la-check"></i> Approva
                                                </button>
                                            </form>

                                            <form method="post" class="approvals-action-form">
                                                <input type="hidden" name="azione" value="rifiuta_richiesta">
                                                <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                                <input type="hidden" name="redirect_query" value="<?= h($redirectFiltri) ?>">
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



<?php layoutFooter(); ?>
