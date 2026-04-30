<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('approvazioni_assenze');

$pdo = db();
$idUtente = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$puoScrivere = haPermessoScrittura('approvazioni_assenze');
$puoConfigurare = haPermessoLettura('configurazione_assenze');

$errore = '';
$messaggio = '';
$richiestePendenti = [];
$richiesteGestite = [];
$riepilogo = [
    'pendenti' => 0,
    'approvate_oggi' => 0,
    'rifiutate_oggi' => 0,
    'gestite_totali' => 0,
];

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function hrScopeLabelApprovazioni(bool $puoConfigurare): string
{
    return $puoConfigurare
        ? 'tutte le richieste in attesa e lo storico completo'
        : 'richieste assegnate a te come responsabile';
}

function hrIdStatoRichiesta(PDO $pdo, string $codice): int
{
    static $cache = [];

    if (isset($cache[$codice])) {
        return $cache[$codice];
    }

    $stmt = $pdo->prepare('SELECT id_stato_richiesta FROM hr_stati_richiesta WHERE codice = :codice LIMIT 1');
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

    $stmt = $pdo->prepare('SELECT id_canale_notifica FROM hr_canali_notifica WHERE codice = :codice AND attivo = 1 LIMIT 1');
    $stmt->execute(['codice' => $codice]);
    $id = $stmt->fetchColumn();
    $cache[$codice] = $id === false ? null : (int)$id;

    return $cache[$codice];
}

function hrCreaNotificaWeb(PDO $pdo, string $tipoEvento, string $titolo, string $messaggio, ?string $link, ?int $idRichiesta, ?int $creatoDa, array $destinatari): void
{
    $destinatari = array_values(array_unique(array_filter(array_map('intval', $destinatari), static fn (int $v): bool => $v > 0)));
    if (count($destinatari) === 0) {
        return;
    }

    $idCanaleWeb = hrIdCanaleNotifica($pdo, 'WEB');
    if ($idCanaleWeb === null) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO hr_notifiche (tipo_evento, titolo, messaggio, link, id_richiesta, creato_da) VALUES (:tipo_evento, :titolo, :messaggio, :link, :id_richiesta, :creato_da)');
    $stmt->execute([
        'tipo_evento' => $tipoEvento,
        'titolo' => $titolo,
        'messaggio' => $messaggio,
        'link' => $link,
        'id_richiesta' => $idRichiesta,
        'creato_da' => $creatoDa,
    ]);

    $idNotifica = (int)$pdo->lastInsertId();
    $stmtDest = $pdo->prepare('INSERT INTO hr_notifiche_destinatari (id_notifica, id_utente, id_canale_notifica, inviata, letta, data_invio) VALUES (:id_notifica, :id_utente, :id_canale_notifica, 1, 0, NOW())');

    foreach ($destinatari as $idUtenteDest) {
        $stmtDest->execute([
            'id_notifica' => $idNotifica,
            'id_utente' => $idUtenteDest,
            'id_canale_notifica' => $idCanaleWeb,
        ]);
    }
}

function hrClasseEsito(string $stato): string
{
    return $stato === 'APPROVATA' ? 'status-ok' : 'status-ko';
}

try {
    $filtroApprovatore = $puoConfigurare ? '1 = 1' : 'a.id_approvatore_assegnato = :id_utente';

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

        $filtroPost = $puoConfigurare ? '' : ' AND a.id_approvatore_assegnato = :id_utente ';
        $stmtRichiesta = $pdo->prepare(
            "SELECT
                r.id_richiesta,
                r.codice_richiesta,
                r.id_utente_richiedente,
                sr.codice AS stato_richiesta_codice,
                a.id_richiesta_approvazione,
                a.stato_approvazione,
                a.id_approvatore_assegnato,
                te.descrizione AS tipologia,
                CONCAT(COALESCE(au.nome, ''), ' ', COALESCE(au.cognome, '')) AS richiedente_nome
             FROM hr_richieste r
             INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
             INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
             INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
             INNER JOIN aut_utenti au ON au.id_utente = r.id_utente_richiedente
             WHERE r.id_richiesta = :id_richiesta
               AND sr.codice = 'IN_ATTESA'
               AND a.stato_approvazione = 'IN_ATTESA'
               {$filtroPost}
             ORDER BY a.livello_approvazione ASC, a.id_richiesta_approvazione ASC
             LIMIT 1"
        );
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

        $stmtUpdApp = $pdo->prepare(
            'UPDATE hr_richieste_approvazioni
             SET stato_approvazione = :stato_approvazione,
                 data_risposta = NOW(),
                 esito = :esito,
                 note_approvatore = :note_approvatore,
                 gestita_da_hr = :gestita_da_hr
             WHERE id_richiesta_approvazione = :id_richiesta_approvazione'
        );
        $stmtUpdApp->execute([
            'stato_approvazione' => $codiceStato,
            'esito' => $codiceStato,
            'note_approvatore' => $notaApprovatore !== '' ? $notaApprovatore : null,
            'gestita_da_hr' => $gestioneHr ? 1 : 0,
            'id_richiesta_approvazione' => (int)$richiesta['id_richiesta_approvazione'],
        ]);

        $stmtUpdRich = $pdo->prepare(
            'UPDATE hr_richieste
             SET id_stato_richiesta = :id_stato_richiesta,
                 data_chiusura = NOW(),
                 data_aggiornamento = NOW()
             WHERE id_richiesta = :id_richiesta'
        );
        $stmtUpdRich->execute([
            'id_stato_richiesta' => $idStato,
            'id_richiesta' => $idRichiesta,
        ]);

        $dettagliStorico = $notaApprovatore !== ''
            ? $notaApprovatore
            : ($azione === 'approva_richiesta' ? 'Richiesta approvata.' : 'Richiesta rifiutata.');
        if ($gestioneHr) {
            $dettagliStorico = ($azione === 'approva_richiesta' ? 'Richiesta approvata da HR/configurazione.' : 'Richiesta rifiutata da HR/configurazione.')
                . ($notaApprovatore !== '' ? ' Nota: ' . $notaApprovatore : '');
        }

        $stmtStorico = $pdo->prepare(
            'INSERT INTO hr_richieste_storico (id_richiesta, azione, id_utente_azione, dettagli, origine)
             VALUES (:id_richiesta, :azione, :id_utente_azione, :dettagli, :origine)'
        );
        $stmtStorico->execute([
            'id_richiesta' => $idRichiesta,
            'azione' => $azioneStorico,
            'id_utente_azione' => $idUtente,
            'dettagli' => $dettagliStorico,
            'origine' => 'web',
        ]);

        hrCreaNotificaWeb(
            $pdo,
            $azione === 'approva_richiesta' ? 'RICHIESTA_ASSENZA_APPROVATA' : 'RICHIESTA_ASSENZA_RIFIUTATA',
            $azione === 'approva_richiesta' ? 'Richiesta approvata' : 'Richiesta rifiutata',
            $azione === 'approva_richiesta'
                ? 'La tua richiesta di ' . trim((string)$richiesta['tipologia']) . ' è stata approvata.'
                : 'La tua richiesta di ' . trim((string)$richiesta['tipologia']) . ' è stata rifiutata.',
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

    $stmtRiepilogo = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN a.stato_approvazione = 'IN_ATTESA' THEN 1 ELSE 0 END) AS pendenti,
            SUM(CASE WHEN a.stato_approvazione = 'APPROVATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS approvate_oggi,
            SUM(CASE WHEN a.stato_approvazione = 'RIFIUTATA' AND DATE(a.data_risposta) = CURDATE() THEN 1 ELSE 0 END) AS rifiutate_oggi,
            SUM(CASE WHEN a.stato_approvazione IN ('APPROVATA', 'RIFIUTATA') THEN 1 ELSE 0 END) AS gestite_totali
         FROM hr_richieste_approvazioni a
         WHERE {$filtroApprovatore}"
    );
    $stmtRiepilogo->execute($puoConfigurare ? [] : ['id_utente' => $idUtente]);
    $r = $stmtRiepilogo->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $riepilogo = [
            'pendenti' => (int)($r['pendenti'] ?? 0),
            'approvate_oggi' => (int)($r['approvate_oggi'] ?? 0),
            'rifiutate_oggi' => (int)($r['rifiutate_oggi'] ?? 0),
            'gestite_totali' => (int)($r['gestite_totali'] ?? 0),
        ];
    }

    $stmtPendenti = $pdo->prepare(
        "SELECT
            r.id_richiesta,
            r.codice_richiesta,
            r.oggetto,
            r.note_richiedente,
            te.descrizione AS tipologia,
            CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, '')) AS richiedente,
            CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, '')) AS approvatore_assegnato,
            DATE_FORMAT(MIN(p.data_da), '%d/%m/%Y') AS data_da,
            DATE_FORMAT(MAX(p.data_a), '%d/%m/%Y') AS data_a,
            TIME_FORMAT(MIN(p.ora_da), '%H:%i') AS ora_da,
            TIME_FORMAT(MAX(p.ora_a), '%H:%i') AS ora_a,
            MIN(p.tipo_periodo) AS tipo_periodo,
            a.id_richiesta_approvazione,
            DATE_FORMAT(a.data_assegnazione, '%d/%m/%Y %H:%i') AS data_assegnazione
         FROM hr_richieste r
         INNER JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta = r.id_stato_richiesta
         INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
         INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
         INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
         LEFT JOIN aut_utenti ua ON ua.id_utente = a.id_approvatore_assegnato
         LEFT JOIN hr_richieste_periodi p ON p.id_richiesta = r.id_richiesta
         WHERE {$filtroApprovatore}
           AND a.stato_approvazione = 'IN_ATTESA'
           AND sr.codice = 'IN_ATTESA'
         GROUP BY r.id_richiesta, r.codice_richiesta, r.oggetto, r.note_richiedente, te.descrizione, richiedente, approvatore_assegnato, a.id_richiesta_approvazione, a.data_assegnazione
         ORDER BY a.data_assegnazione ASC, r.id_richiesta ASC"
    );
    $stmtPendenti->execute($puoConfigurare ? [] : ['id_utente' => $idUtente]);
    $richiestePendenti = $stmtPendenti->fetchAll(PDO::FETCH_ASSOC);

    $stmtGestite = $pdo->prepare(
        "SELECT
            r.id_richiesta,
            r.codice_richiesta,
            te.descrizione AS tipologia,
            CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, '')) AS richiedente,
            CONCAT(COALESCE(ua.nome, ''), ' ', COALESCE(ua.cognome, '')) AS approvatore_assegnato,
            a.stato_approvazione,
            DATE_FORMAT(a.data_risposta, '%d/%m/%Y %H:%i') AS data_risposta,
            a.note_approvatore,
            a.gestita_da_hr
         FROM hr_richieste r
         INNER JOIN hr_richieste_approvazioni a ON a.id_richiesta = r.id_richiesta
         INNER JOIN hr_tipologie_evento te ON te.id_tipologia_evento = r.id_tipologia_evento
         INNER JOIN aut_utenti u ON u.id_utente = r.id_utente_richiedente
         LEFT JOIN aut_utenti ua ON ua.id_utente = a.id_approvatore_assegnato
         WHERE {$filtroApprovatore}
           AND a.stato_approvazione IN ('APPROVATA', 'RIFIUTATA')
         ORDER BY a.data_risposta DESC
         LIMIT 20"
    );
    $stmtGestite->execute($puoConfigurare ? [] : ['id_utente' => $idUtente]);
    $richiesteGestite = $stmtGestite->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

layoutHeader('Approvazioni assenze');
?>

<div class="card card-compact">
    <h1>Approvazioni assenze</h1>
    <div class="meta">
        In questa pagina il responsabile può vedere le richieste assegnate, approvarle o rifiutarle e lasciare una nota per il richiedente.<br>
        <strong>Ambito corrente:</strong> <?= h(hrScopeLabelApprovazioni($puoConfigurare)) ?>
    </div>
</div>

<?php if ($errore !== ''): ?>
    <div class="errore"><?= h($errore) ?></div>
<?php endif; ?>

<?php if ($messaggio !== ''): ?>
    <div class="ok"><?= h($messaggio) ?></div>
<?php endif; ?>

<div class="dashboard-grid" style="margin-bottom: 22px;">
    <div class="dashboard-box"><h3>Pendenti</h3><div class="kpi-number"><?= (int)$riepilogo['pendenti'] ?></div></div>
    <div class="dashboard-box"><h3>Approvate oggi</h3><div class="kpi-number"><?= (int)$riepilogo['approvate_oggi'] ?></div></div>
    <div class="dashboard-box"><h3>Rifiutate oggi</h3><div class="kpi-number"><?= (int)$riepilogo['rifiutate_oggi'] ?></div></div>
    <div class="dashboard-box"><h3>Gestite totali</h3><div class="kpi-number"><?= (int)$riepilogo['gestite_totali'] ?></div></div>
</div>

<div class="card card-wide">
    <div class="section-head">
        <h2>Richieste pendenti</h2>
        <a class="btn btn-outline-primary" href="assenze.php"><i class="la la-calendar" aria-hidden="true"></i> Vai alle mie richieste</a>
    </div>

    <?php if (count($richiestePendenti) === 0): ?>
        <div class="meta">Non hai richieste pendenti da gestire.</div>
    <?php else: ?>
        <div class="approval-list">
            <?php foreach ($richiestePendenti as $r): ?>
                <?php
                $periodo = (string)$r['data_da'];
                if ((string)$r['data_a'] !== '' && (string)$r['data_a'] !== (string)$r['data_da']) {
                    $periodo .= ' → ' . (string)$r['data_a'];
                }
                if ((string)$r['tipo_periodo'] === 'ORE' && (string)$r['ora_da'] !== '' && (string)$r['ora_a'] !== '') {
                    $periodo .= ' · ' . (string)$r['ora_da'] . ' - ' . (string)$r['ora_a'];
                }
                ?>
                <div class="approval-card">
                    <div class="approval-card-head">
                        <div>
                            <div class="approval-title"><?= h((string)$r['richiedente']) ?> · <?= h((string)$r['tipologia']) ?></div>
                            <div class="meta">
                                Codice <?= h((string)$r['codice_richiesta']) ?> · Assegnata il <?= h((string)$r['data_assegnazione']) ?>
                                <?php if ($puoConfigurare): ?>
                                    · Approvatore assegnato: <?= h(trim((string)$r['approvatore_assegnato']) !== '' ? (string)$r['approvatore_assegnato'] : 'Non indicato') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div><span class="status-badge status-wait">In attesa</span></div>
                    </div>

                    <div class="approval-grid">
                        <div>
                            <div class="field-label">Periodo</div>
                            <div><?= h($periodo) ?></div>
                        </div>
                        <div>
                            <div class="field-label">Oggetto</div>
                            <div><?= h(trim((string)$r['oggetto']) !== '' ? (string)$r['oggetto'] : 'Nessun oggetto') ?></div>
                        </div>
                    </div>

                    <div class="field-block">
                        <div class="field-label">Note del richiedente</div>
                        <div class="pre-wrap"><?= h(trim((string)$r['note_richiedente']) !== '' ? (string)$r['note_richiedente'] : 'Nessuna nota') ?></div>
                    </div>

                    <?php if ($puoScrivere): ?>
                        <div class="approval-actions-grid">
                            <form method="post" action="approvazioni_assenze.php" class="approval-form">
                                <input type="hidden" name="azione" value="approva_richiesta">
                                <input type="hidden" name="id_richiesta" value="<?= (int)$r['id_richiesta'] ?>">
                                <label for="nota_approvatore_ok_<?= (int)$r['id_richiesta'] ?>">Nota responsabile</label>
                                <textarea name="nota_approvatore" id="nota_approvatore_ok_<?= (int)$r['id_richiesta'] ?>" placeholder="Nota facoltativa per il richiedente"></textarea>
                                <div class="actions-inline">
                                    <button type="submit" class="btn btn-primary"><i class="la la-check" aria-hidden="true"></i> Approva</button>
                                </div>
                            </form>

                            <form method="post" action="approvazioni_assenze.php" class="approval-form" onsubmit="return confirm('Confermi il rifiuto della richiesta?');">
                                <input type="hidden" name="azione" value="rifiuta_richiesta">
                                <input type="hidden" name="id_richiesta" value="<?= (int)$r['id_richiesta'] ?>">
                                <label for="nota_approvatore_no_<?= (int)$r['id_richiesta'] ?>">Motivazione rifiuto</label>
                                <textarea name="nota_approvatore" id="nota_approvatore_no_<?= (int)$r['id_richiesta'] ?>" placeholder="Motivazione consigliata"></textarea>
                                <div class="actions-inline">
                                    <button type="submit" class="btn btn-outline-danger"><i class="la la-times" aria-hidden="true"></i> Rifiuta</button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card card-wide">
    <h2>Ultime richieste gestite</h2>

    <?php if (count($richiesteGestite) === 0): ?>
        <div class="meta">Non hai ancora gestito richieste.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Codice</th>
                        <th>Richiedente</th>
                        <th>Tipologia</th>
                        <?php if ($puoConfigurare): ?>
                            <th>Approvatore</th>
                        <?php endif; ?>
                        <th>Esito</th>
                        <th>Data risposta</th>
                        <th>Nota</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($richiesteGestite as $r): ?>
                        <tr>
                            <td><strong><?= h((string)$r['codice_richiesta']) ?></strong></td>
                            <td><?= h((string)$r['richiedente']) ?></td>
                            <td><?= h((string)$r['tipologia']) ?></td>
                            <?php if ($puoConfigurare): ?>
                                <td>
                                    <?= h(trim((string)$r['approvatore_assegnato']) !== '' ? (string)$r['approvatore_assegnato'] : 'Non indicato') ?>
                                    <?php if ((int)($r['gestita_da_hr'] ?? 0) === 1): ?>
                                        <br><span class="meta">gestita da HR/configurazione</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td><span class="status-badge <?= hrClasseEsito((string)$r['stato_approvazione']) ?>"><?= h((string)$r['stato_approvazione']) ?></span></td>
                            <td><?= h((string)$r['data_risposta']) ?></td>
                            <td><?= nl2br(h(trim((string)$r['note_approvatore']) !== '' ? (string)$r['note_approvatore'] : '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php layoutFooter(); ?>
