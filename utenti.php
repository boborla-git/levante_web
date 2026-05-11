<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';

richiediPermessoLettura('utenti');

$stmt = $pdo->query("
    SELECT
        u.id_utente,
        u.username,
        u.nome,
        u.cognome,
        u.attivo,
        u.deve_cambiare_password,
        u.data_creazione,
        u.data_aggiornamento,
        ar.codice_ruolo AS ruolo_attivo
    FROM aut_utenti u
    LEFT JOIN aut_utenti_ruoli aur
        ON aur.id_utente = u.id_utente
        AND aur.attivo = 1
        AND (aur.data_fine IS NULL OR aur.data_fine >= NOW())
    LEFT JOIN aut_ruoli ar
        ON ar.id_ruolo = aur.id_ruolo
        AND ar.attivo = 1
    ORDER BY u.username
");

$utenti = $stmt->fetchAll();

layoutHeader('Gestione utenti');
?>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="admin-tabs">
        <span class="admin-tabs-label">Sezione:</span>
        <a class="active" href="utenti.php"><i class="la la-users" aria-hidden="true"></i> Utenti</a>
        <a href="ruoli_utenti.php"><i class="la la-user-tag" aria-hidden="true"></i> Ruoli utenti</a>
        <a href="permessi_ruoli.php"><i class="la la-key" aria-hidden="true"></i> Permessi ruoli</a>
    </div>

    <section class="admin-page-block">
        <div class="admin-section-heading">
            <h2>Gestione utenti</h2>
            <p class="muted">Elenco degli utenti censiti nel portale.</p>
        </div>

        <div class="admin-actions-toolbar">
            <div class="admin-page-actions">
                <a class="btn btn-primary" href="utente_nuovo.php"><i class="la la-user-plus" aria-hidden="true"></i> Nuovo utente</a>
            </div>

            <div class="form-group admin-list-filter">
                <label for="filtroRapidoUtenti">Filtro rapido</label>
                <input
                    type="search"
                    id="filtroRapidoUtenti"
                    placeholder="Cerca in tutte le colonne..."
                    autocomplete="off"
                    data-table-filter="tabellaUtenti"
                >
            </div>
        </div>
    </section>

    <div class="table-wrap">
    <table id="tabellaUtenti">
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Nome e cognome</th>
                <th>Ruolo attivo</th>
                <th>Stato</th>
                <th>Cambio password</th>
                <th>Creato il</th>
                <th>Aggiornato il</th>
                <th>Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($utenti as $utente): ?>
                <?php $nomeCompleto = trim(((string)$utente['nome']) . ' ' . ((string)$utente['cognome'])); ?>
                <tr>
                    <td><?= (int)$utente['id_utente'] ?></td>
                    <td><?= htmlspecialchars((string)$utente['username']) ?></td>
                    <td><?= htmlspecialchars($nomeCompleto !== '' ? $nomeCompleto : (string)$utente['username']) ?></td>
                    <td><?= htmlspecialchars((string)($utente['ruolo_attivo'] ?? 'nessun ruolo')) ?></td>
                    <td>
                        <?php if ((int)$utente['attivo'] === 1): ?>
                            <?= renderHrStatusBadge('ATTIVO', 'Attivo', ['class' => 'user-badge']) ?>
                        <?php else: ?>
                            <?= renderHrStatusBadge('DISATTIVO', 'Disattivo', ['class' => 'user-badge']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$utente['deve_cambiare_password'] === 1): ?>
                            <?= renderHrStatusBadge('OBBLIGATORIO', 'Obbligatorio', ['class' => 'user-badge']) ?>
                        <?php else: ?>
                            <?= renderHrStatusBadge('NO', 'No', ['class' => 'user-badge']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars((string)$utente['data_creazione']) ?></td>
                    <td><?= htmlspecialchars((string)($utente['data_aggiornamento'] ?? '')) ?></td>
                    <td>
                        <?php if ((int)$utente['id_utente'] !== (int)($_SESSION['utente_id'] ?? 0)): ?>
                            <div class="table-actions">
                                <a class="btn btn-sm btn-light" href="utente_reset_password.php?id=<?= (int)$utente['id_utente'] ?>">
                                    <i class="la la-key" aria-hidden="true"></i> Reset password
                                </a>
                                <a class="btn btn-sm btn-light" href="utente_forza_password.php?id=<?= (int)$utente['id_utente'] ?>"
                                   onclick="return confirm('Vuoi obbligare questo utente a cambiare la password al prossimo accesso?');">
                                    <i class="la la-exclamation-circle" aria-hidden="true"></i> Forza cambio password
                                </a>
                            </div>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="links">
        <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Torna alla dashboard</a>
    </div>
</div>


<?php layoutFooter(); ?>
