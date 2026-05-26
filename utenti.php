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
        u.attivo DESC,
        COALESCE(NULLIF(u.cognome, ''), u.username),
        COALESCE(NULLIF(u.nome, ''), u.username),
        u.username"
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
<link rel="stylesheet" href="/assets/admin.css">

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
    <div class="hr-filter-toolbar admin-section-toolbar">
        <div class="admin-section-title">
            <h2>Directory utenti</h2>
            <div class="meta">Vista compatta per verificare rapidamente utenti, ruoli e azioni principali.</div>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="utentiSearch">Filtro rapido</label>
            <input type="search" id="utentiSearch" data-card-filter="utentiCards" placeholder="Cerca persona, ruolo, stato..." autocomplete="off">
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
            <article class="admin-user-card" data-card-filter-item="utentiCards" data-search-text="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>">
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
    <div class="hr-filter-toolbar admin-section-toolbar">
        <div class="admin-section-title">
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

<script src="/assets/hr-common.js"></script>

<?php layoutFooter(); ?>
