<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('profili_dipendenti');

$pdo = db();
$puoScrivere = haPermessoScrittura('profili_dipendenti');
$errore = '';
$messaggio = '';
$profili = [];
$reparti = [];
$centriCosto = [];
$utentiResponsabili = [];
$responsabiliByUtente = [];
$responsabilePrincipaleByUtente = [];
$teamByUtente = [];
$riepilogo = [
    'profili_totali' => 0,
    'profili_reali' => 0,
    'profili_test' => 0,
    'profili_compilati' => 0,
    'senza_reparto' => 0,
    'senza_centro_costo' => 0,
    'senza_responsabile' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrProfiloData(?string $valore): ?string
{
    $valore = trim((string)$valore);
    if ($valore === '') {
        return null;
    }

    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valore) ? $valore : null;
}

function hrProfiloLabelUtente(array $profilo): string
{
    $nome = trim((string)($profilo['nome'] ?? ''));
    $cognome = trim((string)($profilo['cognome'] ?? ''));
    $username = trim((string)($profilo['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    if ($nominativo !== '') {
        return $nominativo;
    }

    return $username;
}

function hrProfiloDescrizioneUtente(array $profilo): string
{
    $username = trim((string)($profilo['username'] ?? ''));
    if ($username === '') {
        return '';
    }

    return '@' . $username;
}

function hrProfiloValore(?string $valore, string $fallback = 'Non assegnato'): string
{
    $valore = trim((string)$valore);
    return $valore !== '' ? $valore : $fallback;
}

function hrProfiloBadgeHtml(string $testo, string $classe = ''): string
{
    $classAttr = trim('hr-profile-pill ' . $classe);
    return '<span class="' . h($classAttr) . '">' . h($testo) . '</span>';
}

function hrProfiloNominativoOpzione(array $utente): string
{
    $nominativo = trim((string)($utente['nominativo'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));

    if ($nominativo !== '') {
        return $nominativo . ($username !== '' ? ' (' . $username . ')' : '');
    }

    return $username;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_profilo') {
            $idProfilo = (int)($_POST['id_profilo_dipendente'] ?? 0);
            if ($idProfilo <= 0) {
                throw new RuntimeException('Profilo dipendente non valido.');
            }

            $stmtProfilo = $pdo->prepare('SELECT id_utente FROM hr_profili_dipendenti WHERE id_profilo_dipendente = :id');
            $stmtProfilo->execute(['id' => $idProfilo]);
            $profiloCorrente = $stmtProfilo->fetch(PDO::FETCH_ASSOC);
            if (!$profiloCorrente) {
                throw new RuntimeException('Profilo dipendente non trovato.');
            }

            $idUtenteProfilo = (int)$profiloCorrente['id_utente'];
            $idReparto = (int)($_POST['id_reparto'] ?? 0);
            $idCentroCosto = (int)($_POST['id_centro_costo'] ?? 0);
            $idResponsabile = (int)($_POST['id_responsabile'] ?? 0);
            $matricola = trim((string)($_POST['matricola'] ?? ''));
            $mansione = trim((string)($_POST['mansione'] ?? ''));
            $dataAssunzione = hrProfiloData((string)($_POST['data_assunzione'] ?? ''));
            $dataCessazione = hrProfiloData((string)($_POST['data_cessazione'] ?? ''));
            $noteHr = trim((string)($_POST['note_hr'] ?? ''));

            if ($dataAssunzione !== null && $dataCessazione !== null && $dataCessazione < $dataAssunzione) {
                throw new RuntimeException('La data di cessazione non può precedere la data di assunzione.');
            }
            if ($idResponsabile > 0 && $idResponsabile === $idUtenteProfilo) {
                throw new RuntimeException('Il responsabile non può coincidere con il dipendente.');
            }

            $idTipoResponsabile = 0;
            $stmtTipo = $pdo->prepare(
                "SELECT id_tipo_relazione
                 FROM hr_tipi_relazione_organizzativa
                 WHERE codice = 'RESPONSABILE_FUNZIONALE'
                    OR codice = 'RESPONSABILE_DIRETTO'
                 ORDER BY CASE WHEN codice = 'RESPONSABILE_FUNZIONALE' THEN 0 ELSE 1 END
                 LIMIT 1"
            );
            $stmtTipo->execute();
            $idTipoResponsabile = (int)$stmtTipo->fetchColumn();
            if ($idResponsabile > 0 && $idTipoResponsabile <= 0) {
                throw new RuntimeException('Tipo relazione responsabile non configurato.');
            }

            if ($idResponsabile > 0) {
                $stmtUtente = $pdo->prepare('SELECT COUNT(*) FROM aut_utenti WHERE id_utente = :id_utente AND attivo = 1');
                $stmtUtente->execute(['id_utente' => $idResponsabile]);
                if ((int)$stmtUtente->fetchColumn() === 0) {
                    throw new RuntimeException('Responsabile selezionato non valido o non attivo.');
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE hr_profili_dipendenti
                 SET matricola = :matricola,
                     mansione = :mansione,
                     id_reparto = :id_reparto,
                     id_centro_costo = :id_centro_costo,
                     data_assunzione = :data_assunzione,
                     data_cessazione = :data_cessazione,
                     note_hr = :note_hr,
                     attivo = :attivo,
                     data_aggiornamento = NOW()
                 WHERE id_profilo_dipendente = :id_profilo_dipendente'
            );
            $stmt->execute([
                'matricola' => $matricola !== '' ? $matricola : null,
                'mansione' => $mansione !== '' ? $mansione : null,
                'id_reparto' => $idReparto > 0 ? $idReparto : null,
                'id_centro_costo' => $idCentroCosto > 0 ? $idCentroCosto : null,
                'data_assunzione' => $dataAssunzione,
                'data_cessazione' => $dataCessazione,
                'note_hr' => $noteHr !== '' ? $noteHr : null,
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'id_profilo_dipendente' => $idProfilo,
            ]);

            $stmtRelazioneAttuale = $pdo->prepare(
                "SELECT ro.id_relazione_organizzativa, ro.id_utente_collegato
                 FROM hr_relazioni_organizzative ro
                 INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
                 WHERE ro.id_utente = :id_utente
                   AND ro.attiva = 1
                   AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
                   AND tro.codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')
                 ORDER BY CASE WHEN tro.codice = 'RESPONSABILE_FUNZIONALE' THEN 0 ELSE 1 END,
                          ro.data_inizio DESC,
                          ro.id_relazione_organizzativa DESC"
            );
            $stmtRelazioneAttuale->execute(['id_utente' => $idUtenteProfilo]);
            $relazioniAttuali = $stmtRelazioneAttuale->fetchAll(PDO::FETCH_ASSOC);

            $responsabileAttuale = 0;
            foreach ($relazioniAttuali as $relazioneAttuale) {
                if ($responsabileAttuale === 0) {
                    $responsabileAttuale = (int)$relazioneAttuale['id_utente_collegato'];
                }
            }

            if ($idResponsabile !== $responsabileAttuale || count($relazioniAttuali) > 1) {
                $stmtChiudi = $pdo->prepare(
                    "UPDATE hr_relazioni_organizzative ro
                     INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
                     SET ro.attiva = 0,
                         ro.data_fine = COALESCE(ro.data_fine, CURDATE()),
                         ro.note = TRIM(CONCAT(COALESCE(ro.note, ''), CASE WHEN COALESCE(ro.note, '') = '' THEN '' ELSE ' | ' END, 'Chiusa da anagrafica HR'))
                     WHERE ro.id_utente = :id_utente
                       AND ro.attiva = 1
                       AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
                       AND tro.codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')"
                );
                $stmtChiudi->execute(['id_utente' => $idUtenteProfilo]);

                if ($idResponsabile > 0) {
                    $stmtInserisci = $pdo->prepare(
                        'INSERT INTO hr_relazioni_organizzative
                         (id_utente, id_utente_collegato, id_tipo_relazione, data_inizio, data_fine, attiva, note)
                         VALUES (:id_utente, :id_utente_collegato, :id_tipo_relazione, CURDATE(), NULL, 1, :note)'
                    );
                    $stmtInserisci->execute([
                        'id_utente' => $idUtenteProfilo,
                        'id_utente_collegato' => $idResponsabile,
                        'id_tipo_relazione' => $idTipoResponsabile,
                        'note' => 'Assegnata da anagrafica HR',
                    ]);
                }
            }

            $pdo->commit();

            header('Location: profili_dipendenti.php?ok=1');
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Profilo dipendente aggiornato correttamente.';
    }

    // Allineamento prudente: crea eventuali profili mancanti per utenti attivi senza assegnare dati HR.
    $pdo->exec(
        'INSERT INTO hr_profili_dipendenti (id_utente, attivo)
         SELECT u.id_utente, 1
         FROM aut_utenti u
         WHERE u.attivo = 1
           AND NOT EXISTS (
               SELECT 1
               FROM hr_profili_dipendenti p
               WHERE p.id_utente = u.id_utente
           )'
    );

    $reparti = $pdo->query('SELECT id_reparto, codice, nome FROM hr_reparti WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);
    $centriCosto = $pdo->query('SELECT id_centro_costo, codice, nome FROM hr_centri_costo WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);
    $utentiResponsabili = $pdo->query(
        "SELECT id_utente,
                username,
                TRIM(CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,''))) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY cognome, nome, username"
    )->fetchAll(PDO::FETCH_ASSOC);

    $profili = $pdo->query(
        'SELECT *
         FROM v_hr_profili_dipendenti
         ORDER BY cognome, nome, utente_test, username'
    )->fetchAll(PDO::FETCH_ASSOC);

    $responsabiliRows = $pdo->query(
        "SELECT ro.id_utente,
                ro.id_utente_collegato,
                tro.codice AS tipo_codice,
                tro.descrizione AS tipo_descrizione,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,'')) AS responsabile_nome,
                u.username AS responsabile_username,
                ro.data_inizio
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
         INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente_collegato
         WHERE ro.attiva = 1
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
         ORDER BY ro.id_utente,
                  CASE WHEN tro.codice = 'RESPONSABILE_FUNZIONALE' THEN 0 WHEN tro.codice = 'RESPONSABILE_DIRETTO' THEN 1 ELSE 2 END,
                  ro.data_inizio DESC,
                  u.cognome,
                  u.nome"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($responsabiliRows as $row) {
        $idUtente = (int)$row['id_utente'];
        $nome = trim((string)($row['responsabile_nome'] ?? ''));
        $username = trim((string)($row['responsabile_username'] ?? ''));
        $label = $nome !== '' ? $nome : $username;
        $tipo = trim((string)($row['tipo_descrizione'] ?? ''));
        $responsabiliByUtente[$idUtente][] = [
            'id_utente_collegato' => (int)$row['id_utente_collegato'],
            'label' => $label,
            'tipo' => $tipo,
            'codice' => trim((string)($row['tipo_codice'] ?? '')),
            'username' => $username,
        ];

        if (!isset($responsabilePrincipaleByUtente[$idUtente]) && in_array((string)$row['tipo_codice'], ['RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO'], true)) {
            $responsabilePrincipaleByUtente[$idUtente] = (int)$row['id_utente_collegato'];
        }
    }

    $teamRows = $pdo->query(
        "SELECT gu.id_utente,
                gl.nome AS gruppo_nome,
                gl.codice AS gruppo_codice,
                gu.ruolo_nel_gruppo
         FROM hr_gruppi_utenti gu
         INNER JOIN hr_gruppi_lavoro gl ON gl.id_gruppo_lavoro = gu.id_gruppo_lavoro
         WHERE gu.attivo = 1
           AND gl.attivo = 1
           AND (gu.data_fine IS NULL OR gu.data_fine >= CURDATE())
         ORDER BY gu.id_utente, gl.nome"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teamRows as $row) {
        $idUtente = (int)$row['id_utente'];
        $teamByUtente[$idUtente][] = [
            'nome' => trim((string)($row['gruppo_nome'] ?? '')),
            'codice' => trim((string)($row['gruppo_codice'] ?? '')),
            'ruolo' => trim((string)($row['ruolo_nel_gruppo'] ?? '')),
        ];
    }

    $riepilogo['profili_totali'] = count($profili);
    foreach ($profili as $profilo) {
        $idUtente = (int)$profilo['id_utente'];
        if ((int)$profilo['utente_test'] === 1) {
            $riepilogo['profili_test']++;
        } else {
            $riepilogo['profili_reali']++;
        }

        if (trim((string)($profilo['reparto'] ?? '')) === '') {
            $riepilogo['senza_reparto']++;
        }
        if (trim((string)($profilo['centro_costo'] ?? '')) === '') {
            $riepilogo['senza_centro_costo']++;
        }
        if (!isset($responsabilePrincipaleByUtente[$idUtente])) {
            $riepilogo['senza_responsabile']++;
        }

        if (
            trim((string)($profilo['matricola'] ?? '')) !== '' ||
            trim((string)($profilo['mansione'] ?? '')) !== '' ||
            trim((string)($profilo['reparto'] ?? '')) !== '' ||
            trim((string)($profilo['centro_costo'] ?? '')) !== '' ||
            isset($responsabilePrincipaleByUtente[$idUtente])
        ) {
            $riepilogo['profili_compilati']++;
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

layoutHeader('Profili dipendenti');
?>
<link rel="stylesheet" href="/assets/hr.css">

<div class="hr-profile-stack">
    <section class="card card-compact">
        <div class="section-head hr-profile-hero">
            <div>
                <h1>Profili dipendenti</h1>
                <div class="meta">Directory organizzativa HR: reparto, centro di costo, mansione, responsabile diretto e team in un'unica scheda leggibile.</div>
            </div>
            <div class="section-head-actions">
                <a class="btn btn-light" href="configurazione_assenze.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla configurazione</a>
            </div>
        </div>
    </section>

    <section class="hr-profile-summary">
        <span><strong><?= (int)$riepilogo['profili_totali'] ?></strong> profili</span>
        <span><strong><?= (int)$riepilogo['profili_reali'] ?></strong> utenti reali</span>
        <span><strong><?= (int)$riepilogo['profili_test'] ?></strong> utenti test</span>
        <span><strong><?= (int)$riepilogo['profili_compilati'] ?></strong> profili compilati</span>
        <span><strong><?= (int)$riepilogo['senza_reparto'] ?></strong> senza reparto</span>
        <span><strong><?= (int)$riepilogo['senza_centro_costo'] ?></strong> senza centro di costo</span>
        <span><strong><?= (int)$riepilogo['senza_responsabile'] ?></strong> senza responsabile</span>
    </section>

    <?php if ($errore !== ''): ?><div class="alert alert-error"><?= h($errore) ?></div><?php endif; ?>
    <?php if ($messaggio !== ''): ?><div class="alert alert-success"><?= h($messaggio) ?></div><?php endif; ?>

    <section class="card card-wide">
        <div class="hr-profile-toolbar">
            <div>
                <h2>Anagrafica organizzativa</h2>
                <div class="meta">Vista unica per HR: i cambi reparto, centro di costo e responsabile aggiornano i dati usati da calendario, approvazioni e visibilità.</div>
            </div>
            <div class="form-group hr-filter-search-group">
                <label for="profiliSearch">Filtro rapido</label>
                <input type="search" id="profiliSearch" data-card-filter="profiliDipendenti" placeholder="Cerca persona, reparto, responsabile, team...">
            </div>
        </div>
    </section>

    <section class="hr-profile-grid" id="profiliGrid">
        <?php foreach ($profili as $profilo): ?>
            <?php
            $idProfilo = (int)$profilo['id_profilo_dipendente'];
            $idUtente = (int)$profilo['id_utente'];
            $isTest = (int)$profilo['utente_test'] === 1;
            $nomeUtente = hrProfiloLabelUtente($profilo);
            $usernameLabel = hrProfiloDescrizioneUtente($profilo);
            $reparto = hrProfiloValore((string)($profilo['reparto'] ?? ''));
            $centroCosto = hrProfiloValore((string)($profilo['centro_costo'] ?? ''));
            $codiceReparto = trim((string)($profilo['codice_reparto'] ?? ''));
            $codiceCentroCosto = trim((string)($profilo['codice_centro_costo'] ?? ''));
            $mansione = hrProfiloValore((string)($profilo['mansione'] ?? ''), 'Mansione non indicata');
            $matricola = hrProfiloValore((string)($profilo['matricola'] ?? ''), 'Matricola non indicata');
            $responsabili = $responsabiliByUtente[$idUtente] ?? [];
            $idResponsabilePrincipale = (int)($responsabilePrincipaleByUtente[$idUtente] ?? 0);
            $teams = $teamByUtente[$idUtente] ?? [];
            $searchText = strtolower(trim(implode(' ', [
                $nomeUtente,
                $usernameLabel,
                $reparto,
                $centroCosto,
                $codiceReparto,
                $codiceCentroCosto,
                $mansione,
                $matricola,
                (string)($profilo['note_hr'] ?? ''),
                implode(' ', array_map(static fn(array $r): string => (string)$r['label'] . ' ' . (string)$r['tipo'], $responsabili)),
                implode(' ', array_map(static fn(array $t): string => (string)$t['nome'] . ' ' . (string)$t['codice'] . ' ' . (string)$t['ruolo'], $teams)),
            ])));
            ?>
            <article class="hr-profile-card <?= $isTest ? 'is-test' : '' ?>" data-card-filter-item="profiliDipendenti" data-search-text="<?= h($searchText) ?>">
                <div class="hr-profile-card-header">
                    <div class="hr-profile-person">
                        <div class="hr-profile-name"><?= h($nomeUtente) ?></div>
                        <div class="hr-profile-username"><?= h($usernameLabel) ?></div>
                        <div class="hr-profile-tags">
                            <?= hrProfiloBadgeHtml($reparto, trim((string)($profilo['reparto'] ?? '')) !== '' ? 'primary' : 'warning') ?>
                            <?= hrProfiloBadgeHtml($codiceCentroCosto !== '' ? $codiceCentroCosto : $centroCosto, trim((string)($profilo['centro_costo'] ?? '')) !== '' ? 'muted' : 'warning') ?>
                            <?php if ($idResponsabilePrincipale <= 0): ?><?= hrProfiloBadgeHtml('Senza responsabile', 'warning') ?><?php endif; ?>
                            <?php if ($isTest): ?><?= hrProfiloBadgeHtml('Test', 'warning') ?><?php else: ?><?= hrProfiloBadgeHtml('Reale', 'admin') ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="hr-profile-status">
                        <?= renderHrStatusBadge((int)$profilo['profilo_attivo'] === 1 ? 'ATTIVO' : 'DISATTIVO', (int)$profilo['profilo_attivo'] === 1 ? 'Attivo' : 'Disattivo') ?>
                    </div>
                </div>

                <div class="hr-profile-body">
                    <div class="hr-profile-main">
                        <div class="hr-profile-info">
                            <div class="hr-profile-info-label">Mansione</div>
                            <div class="hr-profile-info-value"><?= h($mansione) ?></div>
                            <div class="hr-profile-info-sub"><?= h($matricola) ?></div>
                        </div>
                        <div class="hr-profile-info">
                            <div class="hr-profile-info-label">Centro di costo</div>
                            <div class="hr-profile-info-value"><?= h($centroCosto) ?></div>
                            <div class="hr-profile-info-sub"><?= h($codiceCentroCosto !== '' ? $codiceCentroCosto : 'Amministrativo') ?></div>
                        </div>
                    </div>

                    <div class="hr-profile-org">
                        <div class="hr-profile-org-row">
                            <i class="la la-sitemap" aria-hidden="true"></i>
                            <div>
                                <strong>Responsabile / referente</strong><br>
                                <?php if (count($responsabili) > 0): ?>
                                    <?php foreach ($responsabili as $responsabile): ?>
                                        <span><?= h((string)$responsabile['label']) ?></span><span class="meta"> · <?= h((string)$responsabile['tipo']) ?></span><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="hr-profile-empty">Non assegnato</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hr-profile-org-row">
                            <i class="la la-users" aria-hidden="true"></i>
                            <div>
                                <strong>Team</strong><br>
                                <?php if (count($teams) > 0): ?>
                                    <?php foreach ($teams as $team): ?>
                                        <span><?= h((string)$team['nome']) ?></span><?php if ((string)$team['ruolo'] !== ''): ?><span class="meta"> · <?= h((string)$team['ruolo']) ?></span><?php endif; ?><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="hr-profile-empty">Nessun team attivo</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <details class="hr-profile-details">
                    <summary>Dettagli e modifica profilo</summary>
                    <form method="post" action="profili_dipendenti.php" class="hr-profile-form">
                        <input type="hidden" name="azione" value="salva_profilo">
                        <input type="hidden" name="id_profilo_dipendente" value="<?= $idProfilo ?>">

                        <div class="info-box">La modifica del responsabile chiude la relazione responsabile precedente e ne apre una nuova da oggi, senza cancellare lo storico.</div>

                        <div class="hr-profile-form-grid">
                            <div class="form-group">
                                <label for="matricola_<?= $idProfilo ?>">Matricola</label>
                                <input type="text" id="matricola_<?= $idProfilo ?>" name="matricola" value="<?= h((string)($profilo['matricola'] ?? '')) ?>" maxlength="50" <?= $puoScrivere ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="mansione_<?= $idProfilo ?>">Mansione</label>
                                <input type="text" id="mansione_<?= $idProfilo ?>" name="mansione" value="<?= h((string)($profilo['mansione'] ?? '')) ?>" maxlength="150" <?= $puoScrivere ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="reparto_<?= $idProfilo ?>">Reparto</label>
                                <select id="reparto_<?= $idProfilo ?>" name="id_reparto" <?= $puoScrivere ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($reparti as $repartoRow): ?>
                                        <option value="<?= (int)$repartoRow['id_reparto'] ?>" <?= (int)($profilo['id_reparto'] ?? 0) === (int)$repartoRow['id_reparto'] ? 'selected' : '' ?>>
                                            <?= h((string)$repartoRow['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="centro_costo_<?= $idProfilo ?>">Centro di costo</label>
                                <select id="centro_costo_<?= $idProfilo ?>" name="id_centro_costo" <?= $puoScrivere ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($centriCosto as $centroCostoRow): ?>
                                        <option value="<?= (int)$centroCostoRow['id_centro_costo'] ?>" <?= (int)($profilo['id_centro_costo'] ?? 0) === (int)$centroCostoRow['id_centro_costo'] ? 'selected' : '' ?>>
                                            <?= h((string)$centroCostoRow['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group hr-profile-form-wide">
                                <label for="responsabile_<?= $idProfilo ?>">Responsabile funzionale</label>
                                <select id="responsabile_<?= $idProfilo ?>" name="id_responsabile" <?= $puoScrivere ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($utentiResponsabili as $utenteResponsabile): ?>
                                        <?php $idOpzioneResponsabile = (int)$utenteResponsabile['id_utente']; ?>
                                        <?php if ($idOpzioneResponsabile === $idUtente) { continue; } ?>
                                        <option value="<?= $idOpzioneResponsabile ?>" <?= $idResponsabilePrincipale === $idOpzioneResponsabile ? 'selected' : '' ?>>
                                            <?= h(hrProfiloNominativoOpzione($utenteResponsabile)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assunzione_<?= $idProfilo ?>">Data assunzione</label>
                                <input type="date" id="assunzione_<?= $idProfilo ?>" name="data_assunzione" value="<?= h((string)($profilo['data_assunzione'] ?? '')) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="cessazione_<?= $idProfilo ?>">Data cessazione</label>
                                <input type="date" id="cessazione_<?= $idProfilo ?>" name="data_cessazione" value="<?= h((string)($profilo['data_cessazione'] ?? '')) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="note_hr_<?= $idProfilo ?>">Note HR</label>
                            <textarea id="note_hr_<?= $idProfilo ?>" name="note_hr" rows="3" <?= $puoScrivere ? '' : 'readonly' ?>><?= h((string)($profilo['note_hr'] ?? '')) ?></textarea>
                        </div>

                        <div class="hr-profile-form-actions">
                            <label class="meta"><input type="checkbox" name="attivo" value="1" <?= (int)$profilo['profilo_attivo'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> profilo attivo</label>
                            <button type="submit" class="btn btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>
                                <i class="la la-save" aria-hidden="true"></i> Salva profilo
                            </button>
                        </div>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<script src="/assets/hr-common.js"></script>

<?php layoutFooter(); ?>
