<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('utenti');

$pdo = db();

function h(?string $valore): string
{
    return htmlspecialchars((string)$valore, ENT_QUOTES, 'UTF-8');
}

function adminUserDisplayName(array $utente): string
{
    $nome = trim((string)($utente['nome'] ?? ''));
    $cognome = trim((string)($utente['cognome'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    return $nominativo !== '' ? $nominativo : $username;
}

function adminUserInitials(array $utente): string
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

function adminFormatDate(?string $valore): string
{
    $valore = trim((string)$valore);
    if ($valore === '') {
        return 'N/D';
    }

    try {
        return (new DateTime($valore))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $valore;
    }
}

$stmt = $pdo->query(
    "SELECT
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento,
        GROUP_CONCAT(DISTINCT ar.codice_ruolo ORDER BY ar.ordinamento, ar.codice_ruolo SEPARATOR ', ') AS ruoli_attivi
    FROM aut_utenti u
    LEFT JOIN aut_utenti_ruoli aur
        ON aur.id_utente = u.id_utente
        AND aur.attivo = 1
        AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
    LEFT JOIN aut_ruoli ar
        ON ar.id_ruolo = aur.id_ruolo
        AND ar.attivo = 1
    GROUP BY
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento
    ORDER BY
        CASE WHEN TRIM(COALESCE(u.cognome, '')) = '' THEN 1 ELSE 0 END,
        u.cognome ASC,
        u.nome ASC,
        u.username ASC"
);

$utenti = $stmt->fetchAll(PDO::FETCH_ASSOC);

$riepilogo = [
    'totali' => count($utenti),
    'attivi' => 0,
    'disattivi' => 0,
    'senza_ruolo' => 0,
    'cambio_password' => 0,
];

foreach ($utenti as $utente) {
    if ((int)$utente['attivo'] === 1) {
        $riepilogo['attivi']++;
    } else {
        $riepilogo['disattivi']++;
    }

    if (trim((string)($utente['ruoli_attivi'] ?? '')) === '') {
        $riepilogo['senza_ruolo']++;
    }

    if ((int)$utente['deve_cambiare_password'] === 1) {
        $riepilogo['cambio_password']++;
    }
}

$idUtenteCorrente = (int)($_SESSION['utente_id'] ?? 0);

layoutHeader('Gestione utenti');
?>

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Gestione utenti</h1>
            <div class="meta">Directory amministrativa degli utenti del portale: accessi, ruoli attivi, stato e azioni di sicurezza.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-primary" href="utente_nuovo.php"><i class="la la-user-plus" aria-hidden="true"></i> Nuovo utente</a>
            <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="card card-compact">
    <?php renderAdminTabs('utenti'); ?>
</div>

<section class="hr-config-summary">
    <span><strong><?= (int)$riepilogo['totali'] ?></strong> utenti</span>
    <span><strong><?= (int)$riepilogo['attivi'] ?></strong> attivi</span>
    <span><strong><?= (int)$riepilogo['disattivi'] ?></strong> disattivi</span>
    <span><strong><?= (int)$riepilogo['senza_ruolo'] ?></strong> senza ruolo</span>
    <span><strong><?= (int)$riepilogo['cambio_password'] ?></strong> cambio password</span>
</section>

<div class="card card-wide">
    <div class="hr-filter-toolbar admin-directory-toolbar">
        <div>
            <h2>Directory utenti</h2>
            <div class="meta">Vista compatta per verificare rapidamente utenti, ruoli e azioni principali.</div>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="utentiSearch">Filtro rapido</label>
            <input type="search" id="utentiSearch" placeholder="Cerca persona, ruolo, stato..." autocomplete="off">
        </div>
    </div>

    <div class="admin-user-grid" id="utentiCards">
        <?php foreach ($utenti as $utente): ?>
            <?php
            $idUtente = (int)$utente['id_utente'];
            $username = trim((string)$utente['username']);
            $nomeCompleto = adminUserDisplayName($utente);
            $ruoli = trim((string)($utente['ruoli_attivi'] ?? ''));
            $utenteAttivo = (int)$utente['attivo'] === 1;
            $cambioPassword = (int)$utente['deve_cambiare_password'] === 1;
            $searchText = trim($username . ' ' . $nomeCompleto . ' ' . $ruoli . ' ' . ($utenteAttivo ? 'attivo' : 'disattivo') . ' ' . ($cambioPassword ? 'cambio password obbligatorio' : 'password ok'));
            ?>
            <article class="admin-user-card" data-search="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>">
                <div class="admin-user-card-main">
                    <div class="admin-user-avatar" aria-hidden="true"><?= h(adminUserInitials($utente)) ?></div>
                    <div class="admin-user-identity">
                        <h3><?= h($nomeCompleto) ?></h3>
                        <div class="meta"><?= h($username) ?></div>
                    </div>
                    <div class="admin-user-status">
                        <?= $utenteAttivo ? renderHrStatusBadge('ATTIVO', 'Attivo', ['class' => 'user-badge']) : renderHrStatusBadge('DISATTIVO', 'Disattivo', ['class' => 'user-badge']) ?>
                    </div>
                </div>

                <div class="admin-user-card-body">
                    <div class="admin-user-info-box admin-user-info-wide">
                        <span>Ruoli attivi</span>
                        <strong><?= h($ruoli !== '' ? $ruoli : 'Nessun ruolo') ?></strong>
                    </div>
                    <div class="admin-user-info-box">
                        <span>Password</span>
                        <strong><?= $cambioPassword ? 'Cambio richiesto' : 'OK' ?></strong>
                    </div>
                    <div class="admin-user-info-box admin-user-info-date">
                        <span>Creato</span>
                        <strong><?= h(adminFormatDate((string)$utente['data_creazione'])) ?></strong>
                    </div>
                    <div class="admin-user-info-box admin-user-info-date">
                        <span>Aggiornato</span>
                        <strong><?= h(adminFormatDate((string)($utente['data_aggiornamento'] ?? ''))) ?></strong>
                    </div>
                </div>

                <div class="admin-user-card-footer">
                    <div class="admin-user-footer-state">
                        <?php if ($cambioPassword): ?>
                            <?= renderHrStatusBadge('OBBLIGATORIO', 'Cambio password richiesto', ['class' => 'user-badge']) ?>
                        <?php else: ?>
                            <span class="meta">Password verificata</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($idUtente !== $idUtenteCorrente): ?>
                        <div class="admin-user-actions">
                            <a class="btn btn-sm btn-light" href="utente_reset_password.php?id=<?= $idUtente ?>">
                                <i class="la la-key" aria-hidden="true"></i> Reset
                            </a>
                            <a class="btn btn-sm btn-light" href="utente_forza_password.php?id=<?= $idUtente ?>"
                               onclick="return confirm('Vuoi obbligare questo utente a cambiare la password al prossimo accesso?');">
                                <i class="la la-exclamation-circle" aria-hidden="true"></i> Forza
                            </a>
                        </div>
                    <?php else: ?>
                        <span class="meta admin-current-user-note">Utente corrente</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<div class="card card-wide admin-archive-card">
    <div class="hr-filter-toolbar">
        <div>
            <h2>Archivio utenti</h2>
            <div class="meta">Vista tabellare completa per controlli amministrativi.</div>
        </div>
        <?php renderAdminQuickFilter('filtroRapidoUtenti', 'tabellaUtenti'); ?>
    </div>

    <div class="table-wrap">
        <table id="tabellaUtenti">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Nome e cognome</th>
                    <th>Ruoli attivi</th>
                    <th>Stato</th>
                    <th>Cambio password</th>
                    <th>Creato il</th>
                    <th>Aggiornato il</th>
                    <th>Azioni</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($utenti as $utente): ?>
                    <?php
                    $idUtente = (int)$utente['id_utente'];
                    $nomeCompleto = adminUserDisplayName($utente);
                    $ruoli = trim((string)($utente['ruoli_attivi'] ?? ''));
                    ?>
                    <tr>
                        <td><?= $idUtente ?></td>
                        <td><?= h((string)$utente['username']) ?></td>
                        <td><?= h($nomeCompleto) ?></td>
                        <td><?= h($ruoli !== '' ? $ruoli : 'nessun ruolo') ?></td>
                        <td>
                            <?= (int)$utente['attivo'] === 1
                                ? renderHrStatusBadge('ATTIVO', 'Attivo', ['class' => 'user-badge'])
                                : renderHrStatusBadge('DISATTIVO', 'Disattivo', ['class' => 'user-badge']) ?>
                        </td>
                        <td>
                            <?= (int)$utente['deve_cambiare_password'] === 1
                                ? renderHrStatusBadge('OBBLIGATORIO', 'Obbligatorio', ['class' => 'user-badge'])
                                : renderHrStatusBadge('NO', 'No', ['class' => 'user-badge']) ?>
                        </td>
                        <td><?= h(adminFormatDate((string)$utente['data_creazione'])) ?></td>
                        <td><?= h(adminFormatDate((string)($utente['data_aggiornamento'] ?? ''))) ?></td>
                        <td>
                            <?php if ($idUtente !== $idUtenteCorrente): ?>
                                <div class="table-actions">
                                    <a class="btn btn-sm btn-light" href="utente_reset_password.php?id=<?= $idUtente ?>">
                                        <i class="la la-key" aria-hidden="true"></i> Reset password
                                    </a>
                                    <a class="btn btn-sm btn-light" href="utente_forza_password.php?id=<?= $idUtente ?>"
                                       onclick="return confirm('Vuoi obbligare questo utente a cambiare la password al prossimo accesso?');">
                                        <i class="la la-exclamation-circle" aria-hidden="true"></i> Forza cambio password
                                    </a>
                                </div>
                            <?php else: ?>
                                <span class="meta">Utente corrente</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.admin-user-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 12px;
}

.admin-user-card {
    background: #fff;
    border: 1px solid var(--border-color, #d8e2ef);
    border-radius: 14px;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.045);
    overflow: hidden;
}

.admin-user-card-main,
.admin-user-card-footer {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 12px;
}

.admin-user-card-main {
    border-bottom: 1px solid #edf2f7;
}

.admin-user-avatar {
    flex: 0 0 auto;
    width: 38px;
    height: 38px;
    border-radius: 13px;
    display: grid;
    place-items: center;
    font-weight: 800;
    color: #0755c7;
    background: #eaf3ff;
    border: 1px solid #cfe4ff;
}

.admin-user-identity {
    min-width: 0;
    flex: 1;
}

.admin-user-identity h3 {
    margin: 0 0 2px;
    font-size: 0.98rem;
    line-height: 1.15;
}

.admin-user-status {
    flex: 0 0 auto;
}

.admin-user-card-body {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    gap: 8px;
    padding: 10px 12px;
}

.admin-user-info-box {
    border: 1px solid #edf2f7;
    border-radius: 11px;
    padding: 8px 9px;
    min-height: 52px;
    background: #fbfdff;
}

.admin-user-info-wide {
    grid-column: span 2;
}

.admin-user-info-box span {
    display: block;
    color: #52677f;
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.055em;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.admin-user-info-box strong {
    display: block;
    line-height: 1.18;
    overflow-wrap: anywhere;
    font-size: 0.9rem;
}

.admin-user-info-date strong {
    font-size: 0.84rem;
}

.admin-user-card-footer {
    justify-content: space-between;
    border-top: 1px solid #edf2f7;
    background: #fbfdff;
    flex-wrap: wrap;
}

.admin-user-footer-state {
    min-width: 0;
}

.admin-user-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-left: auto;
}

.admin-user-actions .btn,
.table-actions .btn {
    min-height: 34px;
    padding-inline: 10px;
}

.admin-current-user-note {
    margin-left: auto;
}

@media (min-width: 1180px) {
    .admin-user-grid {
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    }
}

@media (max-width: 720px) {
    .admin-directory-toolbar {
        align-items: stretch;
    }

    .admin-user-grid {
        grid-template-columns: 1fr;
    }

    .admin-user-card-main,
    .admin-user-card-footer {
        align-items: flex-start;
    }

    .admin-user-status {
        margin-left: auto;
    }

    .admin-user-card-body {
        grid-template-columns: 1fr;
    }

    .admin-user-info-wide {
        grid-column: auto;
    }

    .admin-user-info-box {
        min-height: 0;
    }

    .admin-user-actions,
    .admin-user-actions .btn {
        width: 100%;
    }

    .admin-user-actions .btn {
        justify-content: center;
    }

    .admin-current-user-note {
        margin-left: 0;
    }

    .admin-archive-card .table-wrap {
        overflow-x: auto;
    }

    .admin-archive-card table {
        min-width: 780px;
    }
}
</style>

<script>
(function () {
    var input = document.getElementById('utentiSearch');
    var cards = Array.prototype.slice.call(document.querySelectorAll('#utentiCards .admin-user-card'));
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
