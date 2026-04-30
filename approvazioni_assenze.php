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
    return $puoConfigurare
        ? 'tutte le richieste'
        : 'solo le richieste assegnate a te';
}

function hrIdStatoRichiesta(PDO $pdo, string $codice): int
{
    static $cache = [];

    if (isset($cache[$codice])) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare("
        SELECT id_stato_richiesta
        FROM hr_stati_richiesta
        WHERE codice = :codice
        LIMIT 1
    ");
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

    $stmt = $pdo->prepare("
        SELECT id_canale_notifica
        FROM hr_canali_notifica
        WHERE codice = :codice
          AND attivo = 1
        LIMIT 1
    ");
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

    $stmt = $pdo->prepare("
        INSERT INTO hr_notifiche
            (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da)
        VALUES
            (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)
    ");
    $stmt->execute([
        'tipo_evento' => $tipoEvento,
        'titolo' => $titolo,
        'messaggio' => $messaggio,
        'link' => $link,
        'id_richiesta' => $idRichiesta,
        'creato_da' => $creatoDa,
    ]);

    $idNotifica = (int)$pdo->lastInsertId();

    $stmtDest = $pdo->prepare("
        INSERT INTO hr_notifiche_destinatari
            (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio)
        VALUES
            (:id_notifica, :id_utente, :id_canale_notifica, 1, 0, NOW())
    ");

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

        $stmtRichiesta = $pdo->prepare("
            SELECT
                r.id_richiesta,
                r.id_utente_richiedente,
                a.id_richiesta_approvazione,
                a.id_approvatore_assegnato,
                te.descrizione AS tipologia,
                CONCAT(COALESCE(au.nome, ''), ' ', COALESCE(au.cognome, '')) AS richiedente_nome
            FROM hr_richieste r
            INNER JOIN hr_stati_richiesta sr
                ON sr.id_stato_richiesta = r.id_stato_richiesta
            INNER JOIN hr_richieste_approvazioni a
                ON a.id_richiesta = r.id_richiesta
            INNER JOIN hr_tipologie_evento te
                ON te.id_tipologia_evento = r.id_tipologia_evento
            INNER JOIN aut_utenti au
                ON au.id_utente = r.id_utente_richiedente
            WHERE r.id_richiesta = :id_richiesta
              AND sr.codice = 'IN_ATTESA'
              AND a.stato_approvazione = 'IN_ATTESA'
              {$filtroPost}
            ORDER BY a.livello_approvazione ASC, a.id_richiesta_approvazione ASC
            LIMIT 1
        ");

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

        $stmtUpdApp = $pdo->prepare("
            UPDATE hr_richieste_approvazioni
            SET
                stato_approvazione = :stato_approvazione,
                data_risposta = NOW(),
                esito = :esito,
                note_approvatore = :note_approvatore,
                gestita_da_hr = :gestita_da_hr
            WHERE id_richiesta_approvazione = :id_richiesta_approvazione
        ");
        $stmtUpdApp->execute([
            'stato_approvazione' => $codiceStato,
            'esito' => $codiceStato,
            'note_approvatore' => $notaApprovatore !== '' ? $notaApprovatore : null,
            'gestita_da_hr' => $gestioneHr ? 1 : 0,
            'id_richiesta_approvazione' => (int)$richiesta['id_richiesta_approvazione'],
        ]);

        $stmtUpdRich = $pdo->prepare("
            UPDATE hr_richieste
            SET
                id_stato_richiesta = :id_stato_richiesta,
                data_chiusura = NOW(),
                data_aggiornamento = NOW()
            WHERE id_richiesta = :id_richiesta
        ");
        $stmtUpdRich->execute([
            'id_stato_richiesta' => $idStato,
            'id_richiesta' => $idRichiesta,
        ]);

        $dettagliStorico = $notaApprovatore !== ''
            ? $notaApprovatore
            : 'Richiesta approvata.';

        if ($gestioneHr) {
            $dettagliStorico = ($azione === 'approva_richiesta'
                ? 'Richiesta approvata da HR/configurazione.'
                : 'Richiesta rifiutata da HR/configurazione.')
                . ($notaApprovatore !== '' ? ' Nota: ' . $notaApprovatore : '');
        }

        $stmtStorico = $pdo->prepare("
            INSERT INTO hr_richieste_storico
                (id_richiesta, azione, id_utente_azione, dettagli, origine)
            VALUES
                (:id_richiesta, :azione, :id_utente_azione, :dettagli, :origine)
        ");
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

    $stmtTipologie = $pdo->query("
        SELECT codice, descrizione
        FROM hr_tipologie_evento
        WHERE attivo = 1
        ORDER BY ordinamento, descrizione
    ");
    $tipologie = $stmtTipologie->fetchAll(PDO::FETCH_ASSOC);

    $stmtRiepilogo = $pdo->prepare("
        SELECT
            SUM(CASE WHEN a.stato_approvazione = 'IN_ATTESA' THEN 1 ELSE 0 END) AS pendenti,
            SUM(CASE WHEN a.stato_approvazione = 'APPROVATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS approvate_oggi,
            SUM(CASE WHEN a.stato_approvazione = 'RIFIUTATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS rifiutate_oggi
        FROM hr_richieste_approvazioni a
        WHERE {$filtroApprovatore}
    ");
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

    $sqlRichieste = "
        SELECT
            r.id_richiesta,
            r.oggetto,
            r.note_richiedente,
            te.descrizione AS tipologia,
            te.codice AS codice_tipologia,
            CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, '')) AS richiedente,
            CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, '')) AS approvatore_assegnato,
            DATE_FORMAT(MIN(p.data_da), '%d/%m/%Y') AS data_da,
            DATE_FORMAT(MAX(p.data_a), '%d/%m/%Y') AS data_a,
            TIME_FORMAT(MIN(p.ora_da), '%H:%i') AS ora_da,
            TIME_FORMAT(MAX(p.ora_a), '%H:%i') AS ora_a,
            MIN(p.tipo_periodo) AS tipo_periodo,
            a.stato_approvazione,
            DATE_FORMAT(a.data_assegnazione, '%d/%m/%Y %H:%i') AS data_assegnazione,
            DATE_FORMAT(a.data_risposta, '%d/%m/%Y %H:%i') AS data_risposta,
            a.note_approvatore,
            a.gestita_da_hr
        FROM hr_richieste r
        INNER JOIN hr_richieste_approvazioni a
            ON a.id_richiesta = r.id_richiesta
        INNER JOIN hr_tipologie_evento te
            ON te.id_tipologia_evento = r.id_tipologia_evento
        INNER JOIN aut_utenti u
            ON u.id_utente = r.id_utente_richiedente
        LEFT JOIN aut_utenti ua
            ON ua.id_utente = a.id_approvatore_assegnato
        LEFT JOIN hr_richieste_periodi p
            ON p.id_richiesta = r.id_richiesta
        WHERE {$filtroApprovatore}
    ";

    if (!empty($whereExtra)) {
        $sqlRichieste .= ' AND ' . implode(' AND ', $whereExtra);
    }

    $sqlRichieste .= "
        GROUP BY
            r.id_richiesta,
            r.oggetto,
            r.note_richiedente,
            te.descrizione,
            te.codice,
            richiedente,
            approvatore_assegnato,
            a.stato_approvazione,
            a.data_assegnazione,
            a.data_risposta,
            a.note_approvatore,
            a.gestita_da_hr
        ORDER BY
            CASE a.stato_approvazione
                WHEN 'IN_ATTESA' THEN 1
                WHEN 'APPROVATA' THEN 2
                WHEN 'RIFIUTATA' THEN 3
                ELSE 9
            END,
            COALESCE(a.data_risposta, a.data_assegnazione) DESC,
            r.id_richiesta DESC
        LIMIT 200
    ";

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

<section class="page-header">
    <div>
        <h1><i class="la la-check-circle"></i> Approvazioni assenze</h1>
        <p class="text-muted">
            Gestisci le richieste in attesa. L'approvazione può essere confermata senza nota;
            il rifiuto richiede sempre una motivazione.
        </p>
        <p><strong>Ambito corrente:</strong> <?= h(hrScopeLabelApprovazioni($puoConfigurare)) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn btn-outline-primary" href="assenze.php">
            <i class="la la-calendar"></i> Le mie richieste
        </a>
    </div>
</section>

<?php if ($messaggio !== ''): ?>
    <div class="alert alert-success"><?= h($messaggio) ?></div>
<?php endif; ?>

<?php if ($errore !== ''): ?>
    <div class="alert alert-danger"><?= h($errore) ?></div>
<?php endif; ?>

<section class="compact-summary">
    <span><strong><?= (int)$riepilogo['visualizzate'] ?></strong> richieste visualizzate</span>
    <span><strong><?= (int)$riepilogo['pendenti'] ?></strong> da gestire</span>
    <span><strong><?= (int)$riepilogo['approvate_oggi'] ?></strong> approvate oggi</span>
    <span><strong><?= (int)$riepilogo['rifiutate_oggi'] ?></strong> rifiutate oggi</span>
</section>

<section class="filters-panel <?= $filtriAttivi ? 'open' : '' ?>" id="filtersPanel">
    <button type="button" class="filters-toggle" onclick="toggleFiltersPanel()" aria-expanded="<?= $filtriAttivi ? 'true' : 'false' ?>">
        <span><i class="la la-filter"></i> Filtri</span>
        <span class="filters-status">
            <?= $filtriAttivi ? 'Filtri attivi' : 'Mostra filtri' ?>
            <i class="la la-angle-down"></i>
        </span>
    </button>

    <div class="filters-body">
        <form method="get" class="filters-form">
            <div class="filters-grid">
                <label>
                    <span>Stato</span>
                    <select name="stato">
                        <option value="" <?= $filtroStato === '' ? 'selected' : '' ?>>Tutti gli stati</option>
                        <option value="IN_ATTESA" <?= $filtroStato === 'IN_ATTESA' ? 'selected' : '' ?>>Pendenti</option>
                        <option value="APPROVATA" <?= $filtroStato === 'APPROVATA' ? 'selected' : '' ?>>Approvate</option>
                        <option value="RIFIUTATA" <?= $filtroStato === 'RIFIUTATA' ? 'selected' : '' ?>>Rifiutate</option>
                    </select>
                </label>

                <label>
                    <span>Dal</span>
                    <input type="date" name="data_da" value="<?= h($filtroDataDa) ?>">
                </label>

                <label>
                    <span>Al</span>
                    <input type="date" name="data_a" value="<?= h($filtroDataA) ?>">
                </label>

                <label>
                    <span>Tipologia</span>
                    <select name="tipologia">
                        <option value="">Tutte</option>
                        <?php foreach ($tipologie as $tipologia): ?>
                            <option value="<?= h((string)$tipologia['codice']) ?>" <?= $filtroTipologia === (string)$tipologia['codice'] ? 'selected' : '' ?>>
                                <?= h((string)$tipologia['descrizione']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Richiedente</span>
                    <input type="text" name="utente" value="<?= h($filtroUtente) ?>" placeholder="Nome o cognome">
                </label>
            </div>

            <div class="filters-actions">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="la la-search"></i> Applica filtri
                </button>
                <a href="approvazioni_assenze.php" class="btn btn-outline-primary btn-sm">
                    <i class="la la-undo"></i> Pulisci
                </a>
            </div>
        </form>
    </div>
</section>

<section class="card" style="padding: 1.2rem;">
    <div class="page-header" style="margin-bottom: 0.8rem;">
        <div>
            <h2>Richieste</h2>
            <p class="text-muted">Sono mostrate solo le informazioni utili alla decisione.</p>
        </div>
    </div>

    <?php if (count($richieste) === 0): ?>
        <p class="text-muted">Nessuna richiesta trovata con i filtri impostati.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
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
                                    <div class="action-row" style="align-items: stretch;">
                                        <form method="post" style="display: flex; gap: 0.45rem; flex-wrap: wrap; margin: 0;">
                                            <input type="hidden" name="azione" value="approva_richiesta">
                                            <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                            <input
                                                type="text"
                                                name="nota_approvatore"
                                                placeholder="Nota opzionale"
                                                aria-label="Nota opzionale per approvazione"
                                                style="min-width: 180px;"
                                            >
                                            <button type="submit" class="btn btn-sm btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>
                                                <i class="la la-check"></i> Approva
                                            </button>
                                        </form>

                                        <form method="post" style="display: flex; gap: 0.45rem; flex-wrap: wrap; margin: 0.45rem 0 0;">
                                            <input type="hidden" name="azione" value="rifiuta_richiesta">
                                            <input type="hidden" name="id_richiesta" value="<?= (int)$richiesta['id_richiesta'] ?>">
                                            <input
                                                type="text"
                                                name="nota_approvatore"
                                                placeholder="Motivo rifiuto obbligatorio"
                                                aria-label="Motivo rifiuto obbligatorio"
                                                required
                                                style="min-width: 220px;"
                                            >
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
        </div>
    <?php endif; ?>
</section>

<script>
function toggleFiltersPanel() {
    var panel = document.getElementById('filtersPanel');
    if (!panel) {
        return;
    }

    panel.classList.toggle('open');

    var toggle = panel.querySelector('.filters-toggle');
    if (toggle) {
        toggle.setAttribute('aria-expanded', panel.classList.contains('open') ? 'true' : 'false');
    }
}
</script>

<?php layoutFooter(); ?>
