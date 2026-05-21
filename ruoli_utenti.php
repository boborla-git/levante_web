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
$ruoloUtenteMappa = [];

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

function adminRuoloLabel(array $ruoli, int $idRuolo): string
{
    foreach ($ruoli as $ruolo) {
        if ((int)$ruolo['id_ruolo'] === $idRuolo) {
            return (string)$ruolo['codice_ruolo'];
        }
    }
    return 'Nessun ruolo';
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
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di utenti o ruoli.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        foreach ($utenti as $utente) {
            $utenteId = (int)$utente['id_utente'];
            $chiave = 'ruolo_utente_' . $utenteId;
            $idRuoloSelezionato = (int)($_POST[$chiave] ?? 0);

            $stmtDisattiva = $pdo->prepare(
                "UPDATE aut_utenti_ruoli
                 SET attivo = 0,
                     data_fine = NOW()
                 WHERE id_utente = :id_utente
                   AND attivo = 1"
            );
            $stmtDisattiva->execute(['id_utente' => $utenteId]);

            if ($idRuoloSelezionato > 0) {
                $stmtInserisci = $pdo->prepare(
                    "INSERT INTO aut_utenti_ruoli
                     (id_utente, id_ruolo, data_inizio, data_fine, attivo)
                     VALUES (:id_utente, :id_ruolo, NOW(), NULL, 1)
                     ON DUPLICATE KEY UPDATE
                         attivo = 1,
                         data_inizio = NOW(),
                         data_fine = NULL"
                );
                $stmtInserisci->execute([
                    'id_utente' => $utenteId,
                    'id_ruolo' => $idRuoloSelezionato,
                ]);
            }
        }

        $pdo->commit();
        header('Location: ruoli_utenti.php?ok=1');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errore = 'Errore durante il salvataggio dei ruoli utenti.';
    }
}

try {
    $stmtRuoliUtenti = $pdo->query(
        "SELECT id_utente, id_ruolo
         FROM aut_utenti_ruoli
         WHERE attivo = 1"
    );

    while ($riga = $stmtRuoliUtenti->fetch(PDO::FETCH_ASSOC)) {
        $ruoloUtenteMappa[(int)$riga['id_utente']] = (int)$riga['id_ruolo'];
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei ruoli utente.');
}

if (isset($_GET['ok'])) {
    $messaggio = 'Ruoli utenti aggiornati correttamente.';
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
    $idRuolo = (int)($ruoloUtenteMappa[$idUtente] ?? 0);
    if ((int)$utente['attivo'] === 1) {
        $riepilogo['attivi']++;
    }
    if ($idRuolo <= 0) {
        $riepilogo['senza_ruolo']++;
    } elseif (isset($conteggioRuoli[$idRuolo])) {
        $conteggioRuoli[$idRuolo]++;
    }
}

layoutHeader('Ruoli utenti');
?>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Ruoli utenti</h1>
            <div class="meta">Assegna il ruolo attivo agli utenti del portale. I permessi vengono ereditati dal ruolo selezionato.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Dashboard</a>
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

<section class="card card-wide">
    <div class="hr-filter-toolbar admin-section-toolbar">
        <div class="admin-section-title">
            <h2>Ruoli disponibili</h2>
            <div class="meta">Vista sintetica dei ruoli attivi e del numero di utenti assegnati.</div>
        </div>
    </div>
    <div class="admin-role-summary-grid">
        <?php foreach ($ruoli as $ruolo): ?>
            <?php $idRuolo = (int)$ruolo['id_ruolo']; ?>
            <article class="admin-role-summary-card">
                <div>
                    <h3><?= h((string)$ruolo['codice_ruolo']) ?></h3>
                    <p><?= h((string)($ruolo['descrizione'] ?? '')) ?></p>
                </div>
                <div class="admin-role-count"><strong><?= (int)($conteggioRuoli[$idRuolo] ?? 0) ?></strong><span>utenti</span></div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<form method="post" id="ruoliUtentiForm">
    <section class="card card-wide">
        <div class="hr-filter-toolbar admin-section-toolbar">
            <div class="admin-section-title">
                <h2>Assegnazione ruoli</h2>
                <div class="meta">Modifica i ruoli degli utenti e salva tutto con un'unica conferma finale.</div>
            </div>
            <div class="form-group hr-filter-search-group">
                <label for="ruoliUtentiSearch">Filtro rapido</label>
                <input type="search" id="ruoliUtentiSearch" placeholder="Cerca persona, ruolo, stato..." autocomplete="off">
            </div>
        </div>

        <div class="admin-role-user-grid" id="ruoliUtentiCards">
            <?php foreach ($utenti as $utente): ?>
                <?php
                $utenteId = (int)$utente['id_utente'];
                $chiave = 'ruolo_utente_' . $utenteId;
                $ruoloCorrente = (int)($ruoloUtenteMappa[$utenteId] ?? 0);
                $ruoloCorrenteLabel = adminRuoloLabel($ruoli, $ruoloCorrente);
                $nomeCompleto = adminRuoliUserDisplayName($utente);
                $username = trim((string)$utente['username']);
                $utenteAttivo = (int)$utente['attivo'] === 1;
                $searchText = mb_strtolower(trim($username . ' ' . $nomeCompleto . ' ' . $ruoloCorrenteLabel . ' ' . ($utenteAttivo ? 'attivo' : 'disattivo')), 'UTF-8');
                ?>
                <article class="admin-role-user-card" data-search="<?= h($searchText) ?>">
                    <div class="admin-role-user-head">
                        <div class="admin-role-user-avatar" aria-hidden="true"><?= h(adminRuoliUserInitials($utente)) ?></div>
                        <div class="admin-role-user-title">
                            <h3><?= h($nomeCompleto) ?></h3>
                            <div class="meta"><?= h($username) ?></div>
                        </div>
                        <div class="admin-role-user-status">
                            <?= renderHrStatusBadge($utenteAttivo ? 'ATTIVO' : 'DISATTIVO', $utenteAttivo ? 'Attivo' : 'Disattivo', ['class' => 'user-badge']) ?>
                        </div>
                    </div>
                    <div class="admin-role-current">
                        <span>Ruolo attuale</span>
                        <strong><?= h($ruoloCorrenteLabel) ?></strong>
                    </div>
                    <div class="form-group admin-role-select-group">
                        <label for="<?= h($chiave) ?>">Nuovo ruolo</label>
                        <select class="role-select" id="<?= h($chiave) ?>" name="<?= h($chiave) ?>">
                            <option value="0" <?= $ruoloCorrente === 0 ? 'selected' : '' ?>>nessun ruolo</option>
                            <?php foreach ($ruoli as $ruolo): ?>
                                <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= $ruoloCorrente === (int)$ruolo['id_ruolo'] ? 'selected' : '' ?>>
                                    <?= h((string)$ruolo['codice_ruolo']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php renderAdminSaveActions('Salva ruoli utenti'); ?>
    </section>

    <section class="card card-wide">
        <div class="hr-filter-toolbar admin-section-toolbar">
            <div class="admin-section-title">
                <h2>Archivio assegnazioni</h2>
                <div class="meta">Vista tabellare completa per controllo amministrativo.</div>
            </div>
            <?php renderAdminQuickFilter('filtroRapidoRuoliUtenti', 'tabellaRuoliUtenti'); ?>
        </div>
        <div class="table-wrap">
            <table id="tabellaRuoliUtenti">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Nome</th>
                        <th>Stato</th>
                        <th>Ruolo attivo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($utenti as $utente): ?>
                        <?php
                        $utenteId = (int)$utente['id_utente'];
                        $ruoloCorrente = (int)($ruoloUtenteMappa[$utenteId] ?? 0);
                        $nomeCompleto = adminRuoliUserDisplayName($utente);
                        $utenteAttivo = (int)$utente['attivo'] === 1;
                        ?>
                        <tr>
                            <td><?= $utenteId ?></td>
                            <td><?= h((string)$utente['username']) ?></td>
                            <td><?= h($nomeCompleto) ?></td>
                            <td><?= renderHrStatusBadge($utenteAttivo ? 'ATTIVO' : 'DISATTIVO', $utenteAttivo ? 'Attivo' : 'Disattivo', ['class' => 'user-badge']) ?></td>
                            <td><?= h(adminRuoloLabel($ruoli, $ruoloCorrente)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</form>

<style>

.admin-section-toolbar {
    align-items: flex-start;
    justify-content: flex-start;
    text-align: left;
    gap: 18px;
}

.admin-section-toolbar .admin-section-title {
    flex: 1 1 auto;
    min-width: 0;
    text-align: left;
}

.admin-section-toolbar .admin-section-title h2 {
    margin-left: 0;
    text-align: left;
}

.admin-section-toolbar .hr-filter-search-group,
.admin-section-toolbar .admin-list-filter {
    flex: 0 0 min(360px, 100%);
    margin-left: auto;
}
.admin-role-summary-grid,
.admin-role-user-grid {
    display: grid;
    gap: 14px;
}

.admin-role-summary-grid {
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
}

.admin-role-user-grid {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.admin-role-summary-card,
.admin-role-user-card {
    background: #fff;
    border: 1px solid var(--border-color, #d8e2ef);
    border-radius: 14px;
    box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
}

.admin-role-summary-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 14px;
    min-width: 0;
    overflow: hidden;
}

.admin-role-summary-card > div:first-child {
    min-width: 0;
    flex: 1 1 auto;
}

.admin-role-summary-card h3,
.admin-role-user-title h3 {
    margin: 0 0 4px;
    font-size: 1rem;
    line-height: 1.2;
}

.admin-role-summary-card p {
    margin: 0;
    color: #52677f;
    line-height: 1.35;
}

.admin-role-count {
    flex: 0 0 62px;
    width: 62px;
    min-width: 62px;
    height: 62px;
    border-radius: 999px;
    display: grid;
    place-items: center;
    align-content: center;
    color: #0755c7;
    background: #eaf3ff;
    border: 1px solid #cfe4ff;
    text-align: center;
}

.admin-role-count strong {
    font-size: 1.05rem;
    line-height: 1;
}

.admin-role-count span {
    font-size: 0.64rem;
    font-weight: 800;
    text-transform: uppercase;
}

.admin-role-user-card {
    overflow: hidden;
}

.admin-role-user-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px;
    border-bottom: 1px solid #edf2f7;
}

.admin-role-user-avatar {
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    font-weight: 800;
    color: #0755c7;
    background: #eaf3ff;
    border: 1px solid #cfe4ff;
}

.admin-role-user-title {
    min-width: 0;
    flex: 1;
}

.admin-role-user-status {
    flex: 0 0 auto;
}

.admin-role-current {
    margin: 14px 14px 0;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    padding: 10px;
    background: #fbfdff;
}

.admin-role-current span,
.admin-role-select-group label {
    display: block;
    color: #52677f;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.admin-role-current strong {
    display: block;
    overflow-wrap: anywhere;
}

.admin-role-select-group {
    padding: 14px;
    margin: 0;
}

.admin-role-select-group select {
    width: 100%;
}

@media (max-width: 720px) {
    .admin-section-toolbar {
        display: flex;
        flex-direction: column;
        align-items: stretch;
    }

    .admin-section-toolbar .hr-filter-search-group,
    .admin-section-toolbar .admin-list-filter {
        flex: 1 1 auto;
        width: 100%;
        margin-left: 0;
    }

    .admin-role-summary-grid,
    .admin-role-user-grid {
        grid-template-columns: 1fr;
    }

    .admin-role-summary-card {
        align-items: center;
    }

    .admin-role-user-head {
        align-items: flex-start;
    }

    .admin-role-user-status {
        margin-left: auto;
    }
}
</style>

<script>
(function () {
    var input = document.getElementById('ruoliUtentiSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('#ruoliUtentiCards .admin-role-user-card'));
    if (!input || cards.length === 0) {
        return;
    }

    input.addEventListener('input', function () {
        var query = input.value.trim().toLowerCase();
        cards.forEach(function (card) {
            var text = card.getAttribute('data-search') || '';
            card.style.display = text.indexOf(query) !== -1 ? '' : 'none';
        });
    });
}());
</script>

<?php layoutFooter(); ?>
