<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoScrittura('utenti');

$pdo = db();
$errore = '';
$messaggio = '';
$utenti = [];
$ruoli = [];
$ruoliUtenteAttivi = [];

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function adminRuoliUserDisplayName(array $utente): string
{
    $nome = trim((string)($utente['nome'] ?? ''));
    $cognome = trim((string)($utente['cognome'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);
    return $nominativo !== '' ? $nominativo : $username;
}

function adminRuoliUserInitials(array $utente): string
{
    $nome = trim((string)($utente['nome'] ?? ''));
    $cognome = trim((string)($utente['cognome'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));
    $iniziali = '';

    if ($nome !== '') {
        $iniziali .= mb_substr($nome, 0, 1, 'UTF-8');
    }
    if ($cognome !== '') {
        $iniziali .= mb_substr($cognome, 0, 1, 'UTF-8');
    }
    if ($iniziali === '' && $username !== '') {
        $iniziali = mb_substr($username, 0, 2, 'UTF-8');
    }

    return mb_strtoupper($iniziali !== '' ? $iniziali : '?', 'UTF-8');
}

function adminRuoloLabel(array $ruoliPerId, int $idRuolo): string
{
    return isset($ruoliPerId[$idRuolo])
        ? (string)$ruoliPerId[$idRuolo]['codice_ruolo']
        : 'Ruolo non disponibile';
}

function adminRuoliCorrentiLabel(array $ruoliPerId, array $idsRuolo): string
{
    if (!$idsRuolo) {
        return 'Nessun ruolo';
    }

    $etichette = [];
    foreach ($idsRuolo as $idRuolo) {
        $etichette[] = adminRuoloLabel($ruoliPerId, (int)$idRuolo);
    }

    return implode(', ', $etichette);
}

function adminUtenteRuoloProtetto(array $utente): bool
{
    $username = strtolower(trim((string)($utente['username'] ?? '')));
    return in_array($username, ['admin', 'amministratore'], true);
}

try {
    $stmtUtenti = $pdo->query(
        "SELECT
            id_utente,
            username,
            nome,
            cognome,
            attivo
        FROM aut_utenti
        ORDER BY
            attivo DESC,
            CASE WHEN COALESCE(cognome, '') = '' THEN 1 ELSE 0 END,
            cognome ASC,
            nome ASC,
            username ASC"
    );
    $utenti = $stmtUtenti->fetchAll(PDO::FETCH_ASSOC);

    $stmtRuoli = $pdo->query(
        "SELECT
            id_ruolo,
            codice_ruolo,
            descrizione,
            attivo,
            ordinamento
        FROM aut_ruoli
        WHERE attivo = 1
        ORDER BY ordinamento, codice_ruolo"
    );
    $ruoli = $stmtRuoli->fetchAll(PDO::FETCH_ASSOC);

    $stmtRuoliUtenti = $pdo->query(
        "SELECT id_utente, id_ruolo
         FROM aut_utenti_ruoli
         WHERE attivo = 1
           AND (data_fine IS NULL OR data_fine >= NOW())
         ORDER BY id_utente, id_utente_ruolo"
    );

    while ($riga = $stmtRuoliUtenti->fetch(PDO::FETCH_ASSOC)) {
        $idUtente = (int)$riga['id_utente'];
        $ruoliUtenteAttivi[$idUtente][] = (int)$riga['id_ruolo'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di utenti o ruoli.');
}

$ruoliPerId = [];
foreach ($ruoli as $ruolo) {
    $ruoliPerId[(int)$ruolo['id_ruolo']] = $ruolo;
}
$idsRuoliValidi = array_fill_keys(array_keys($ruoliPerId), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modificati = 0;

    try {
        $pdo->beginTransaction();

        $stmtDisattiva = $pdo->prepare(
            "UPDATE aut_utenti_ruoli
             SET attivo = 0,
                 data_fine = NOW()
             WHERE id_utente = :id_utente
               AND attivo = 1
               AND (data_fine IS NULL OR data_fine >= NOW())"
        );

        $stmtInserisci = $pdo->prepare(
            "INSERT INTO aut_utenti_ruoli
                (id_utente, id_ruolo, data_inizio, data_fine, attivo)
             VALUES
                (:id_utente, :id_ruolo, NOW(), NULL, 1)
             ON DUPLICATE KEY UPDATE
                attivo = 1,
                data_inizio = NOW(),
                data_fine = NULL"
        );

        foreach ($utenti as $utente) {
            if (adminUtenteRuoloProtetto($utente)) {
                continue;
            }

            $utenteId = (int)$utente['id_utente'];
            $chiave = 'ruolo_utente_' . $utenteId;

            if (!array_key_exists($chiave, $_POST)) {
                continue;
            }

            $idRuoloSelezionato = (int)$_POST[$chiave];

            // -1 viene usato solo quando l'utente possiede piu' ruoli attivi:
            // significa "non modificare" e impedisce riduzioni involontarie.
            if ($idRuoloSelezionato === -1) {
                continue;
            }

            if ($idRuoloSelezionato < 0 || ($idRuoloSelezionato > 0 && !isset($idsRuoliValidi[$idRuoloSelezionato]))) {
                throw new RuntimeException('Ruolo selezionato non valido.');
            }

            $correnti = array_values(array_unique(array_map(
                'intval',
                $ruoliUtenteAttivi[$utenteId] ?? []
            )));

            $nessunRuoloCorrente = count($correnti) === 0;
            $singoloRuoloInvariato = count($correnti) === 1 && $correnti[0] === $idRuoloSelezionato;
            $nessunRuoloInvariato = $nessunRuoloCorrente && $idRuoloSelezionato === 0;

            if ($singoloRuoloInvariato || $nessunRuoloInvariato) {
                continue;
            }

            $stmtDisattiva->execute(['id_utente' => $utenteId]);

            if ($idRuoloSelezionato > 0) {
                $stmtInserisci->execute([
                    'id_utente' => $utenteId,
                    'id_ruolo' => $idRuoloSelezionato,
                ]);
            }

            $modificati++;
        }

        $pdo->commit();

        header('Location: ruoli_utenti.php?ok=1&modificati=' . $modificati);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errore = $e->getMessage() === 'Ruolo selezionato non valido.'
            ? $e->getMessage()
            : 'Errore durante il salvataggio dei ruoli utenti.';
    }
}

if (isset($_GET['ok'])) {
    $modificati = max(0, (int)($_GET['modificati'] ?? 0));
    $messaggio = $modificati > 0
        ? ($modificati === 1 ? '1 ruolo utente aggiornato correttamente.' : $modificati . ' ruoli utente aggiornati correttamente.')
        : 'Nessuna modifica ai ruoli: le assegnazioni erano già aggiornate.';
}

$riepilogo = [
    'utenti' => count($utenti),
    'attivi' => 0,
    'senza_ruolo' => 0,
    'ruoli_disponibili' => count($ruoli),
];

$conteggioRuoli = [];
foreach ($ruoli as $ruolo) {
    $conteggioRuoli[(int)$ruolo['id_ruolo']] = 0;
}

foreach ($utenti as $utente) {
    $idUtente = (int)$utente['id_utente'];
    $ruoliCorrenti = array_values(array_unique(array_map(
        'intval',
        $ruoliUtenteAttivi[$idUtente] ?? []
    )));

    if ((int)$utente['attivo'] === 1) {
        $riepilogo['attivi']++;
    }

    if (count($ruoliCorrenti) === 0) {
        $riepilogo['senza_ruolo']++;
    }

    foreach ($ruoliCorrenti as $idRuolo) {
        if (isset($conteggioRuoli[$idRuolo])) {
            $conteggioRuoli[$idRuolo]++;
        }
    }
}

layoutHeader('Ruoli utenti');
?>
<link rel="stylesheet" href="/assets/admin.css">

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Ruoli utenti</h1>
            <div class="meta">Assegna o modifica il ruolo degli utenti esistenti. I permessi vengono ereditati dal ruolo selezionato.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="utenti.php"><i class="la la-arrow-left" aria-hidden="true"></i> Gestione utenti</a>
        </div>
    </div>
</div>

<div class="card card-compact">
    <?php renderAdminTabs('ruoli_utenti'); ?>
</div>

<section class="hr-config-summary">
    <span><strong><?= (int)$riepilogo['utenti'] ?></strong> utenti</span>
    <span><strong><?= (int)$riepilogo['attivi'] ?></strong> attivi</span>
    <span><strong><?= (int)$riepilogo['senza_ruolo'] ?></strong> senza ruolo</span>
    <span><strong><?= (int)$riepilogo['ruoli_disponibili'] ?></strong> ruoli disponibili</span>
</section>

<?php renderAdminAlert($errore, 'danger'); ?>
<?php renderAdminAlert($messaggio, 'success'); ?>

<section class="card card-wide admin-roles-summary">
    <div class="admin-section-title">
        <h2>Ruoli disponibili</h2>
        <div class="meta">Riepilogo compatto dei ruoli attivi e del numero di utenti assegnati.</div>
    </div>

    <?php
    $ruoliAssegnati = array_values(array_filter(
        $ruoli,
        static function (array $ruolo) use ($conteggioRuoli): bool {
            $idRuolo = (int)$ruolo['id_ruolo'];
            return (int)($conteggioRuoli[$idRuolo] ?? 0) > 0;
        }
    ));
    ?>
    <?php if ($ruoliAssegnati): ?>
        <div class="admin-role-chip-list">
            <?php foreach ($ruoliAssegnati as $ruolo): ?>
                <?php
                $idRuolo = (int)$ruolo['id_ruolo'];
                $descrizioneRuolo = trim((string)($ruolo['descrizione'] ?? ''));
                ?>
                <span class="admin-role-chip" title="<?= h($descrizioneRuolo) ?>">
                    <strong><?= h((string)$ruolo['codice_ruolo']) ?></strong>
                    <span><?= (int)($conteggioRuoli[$idRuolo] ?? 0) ?></span>
                </span>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="meta">Nessun ruolo attualmente assegnato.</div>
    <?php endif; ?>
</section>

<form method="post" id="ruoliUtentiForm">
    <section class="card card-wide admin-roles-card">
        <div class="hr-filter-toolbar admin-section-toolbar">
            <div class="admin-section-title">
                <h2>Assegnazione ruoli</h2>
                <div class="meta">Vengono aggiornati solo gli utenti per i quali il ruolo cambia realmente.</div>
            </div>
            <?php renderAdminQuickFilter('filtroRapidoRuoliUtenti', 'tabellaRuoliUtenti', 'Cerca persona, username, ruolo, stato...'); ?>
        </div>

        <div class="table-wrap admin-roles-table-wrap">
            <table id="tabellaRuoliUtenti" class="admin-roles-table">
                <thead>
                    <tr>
                        <th>Dipendente</th>
                        <th>Ruolo attuale</th>
                        <th>Nuovo ruolo</th>
                        <th>Stato</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($utenti as $utente): ?>
                    <?php
                    $utenteId = (int)$utente['id_utente'];
                    $chiave = 'ruolo_utente_' . $utenteId;
                    $ruoliCorrenti = array_values(array_unique(array_map(
                        'intval',
                        $ruoliUtenteAttivi[$utenteId] ?? []
                    )));
                    $ruoloCorrenteLabel = adminRuoliCorrentiLabel($ruoliPerId, $ruoliCorrenti);
                    $nomeCompleto = adminRuoliUserDisplayName($utente);
                    $username = trim((string)$utente['username']);
                    $utenteAttivo = (int)$utente['attivo'] === 1;
                    $utenteProtetto = adminUtenteRuoloProtetto($utente);
                    $piuRuoliAttivi = count($ruoliCorrenti) > 1;
                    $ruoloCorrenteNonDisponibile =
                        count($ruoliCorrenti) === 1
                        && !isset($ruoliPerId[$ruoliCorrenti[0]]);
                    $ruoloSelezionato =
                        count($ruoliCorrenti) === 1 && !$ruoloCorrenteNonDisponibile
                            ? $ruoliCorrenti[0]
                            : (($piuRuoliAttivi || $ruoloCorrenteNonDisponibile) ? -1 : 0);
                    $testoFiltro = trim(
                        $nomeCompleto . ' ' .
                        $username . ' ' .
                        $ruoloCorrenteLabel . ' ' .
                        ($utenteAttivo ? 'attivo' : 'disattivo')
                    );
                    ?>
                    <tr data-filter-text="<?= h($testoFiltro) ?>">
                        <td class="admin-roles-person" data-label="Dipendente">
                            <div class="admin-users-person-main">
                                <div class="admin-user-avatar" aria-hidden="true"><?= h(adminRuoliUserInitials($utente)) ?></div>
                                <div class="admin-users-person-text">
                                    <strong><?= h($nomeCompleto) ?></strong>
                                    <span><?= h($username) ?></span>
                                    <?php if ($utenteProtetto): ?>
                                        <small class="admin-role-protected-note"><i class="la la-lock" aria-hidden="true"></i> Account tecnico protetto</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td data-label="Ruolo attuale">
                            <strong class="admin-roles-current"><?= h($ruoloCorrenteLabel) ?></strong>
                            <?php if ($piuRuoliAttivi): ?>
                                <div class="meta">Più ruoli attivi: nessuna modifica automatica.</div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Nuovo ruolo">
                            <?php if ($utenteProtetto): ?>
                                <span class="admin-role-locked"><i class="la la-lock" aria-hidden="true"></i> Non modificabile</span>
                            <?php else: ?>
                                <select class="role-select admin-role-select" id="<?= h($chiave) ?>" name="<?= h($chiave) ?>">
                                    <?php if ($piuRuoliAttivi || $ruoloCorrenteNonDisponibile): ?>
                                        <option value="-1" selected>— Nessuna modifica —</option>
                                    <?php endif; ?>
                                    <option value="0" <?= $ruoloSelezionato === 0 ? 'selected' : '' ?>>Nessun ruolo</option>
                                    <?php foreach ($ruoli as $ruolo): ?>
                                        <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= $ruoloSelezionato === (int)$ruolo['id_ruolo'] ? 'selected' : '' ?>>
                                            <?= h((string)$ruolo['codice_ruolo']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td data-label="Stato">
                            <?= renderHrStatusBadge(
                                $utenteAttivo ? 'ATTIVO' : 'DISATTIVO',
                                $utenteAttivo ? 'Attivo' : 'Disattivo',
                                ['class' => 'user-badge']
                            ) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="admin-filter-empty" data-quick-filter-empty="tabellaRuoliUtenti" style="display:none">Nessun utente corrisponde al filtro.</div>
        </div>

        <?php renderAdminSaveActions('Salva modifiche ruoli'); ?>
    </section>
</form>

<?php layoutFooter(); ?>
