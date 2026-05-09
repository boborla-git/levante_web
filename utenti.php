<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

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

function classeBadgeUtente(string $tipo): string
{
    if ($tipo === 'ok') {
        return 'status-ok';
    }
    if ($tipo === 'wait') {
        return 'status-wait';
    }
    if ($tipo === 'ko') {
        return 'status-ko';
    }
    return 'status-neutral';
}

layoutHeader('Gestione utenti');
?>

<style>
.hr-filter-toolbar {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) minmax(280px, 420px);
    gap: 16px;
    align-items: end;
    margin-bottom: 16px;
}
.hr-filter-toolbar-main {
    min-width: 0;
}
.hr-filter-search-group {
    margin-bottom: 0;
}
.hr-filter-search-group input[type="search"] {
    width: 100%;
    min-height: 40px;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 8px 12px;
    font: inherit;
    color: #0f172a;
    background: #fff;
    box-sizing: border-box;
}
.hr-filter-search-group input[type="search"]:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.16);
}
.admin-page-actions {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-top: .9rem;
    flex-wrap: wrap;
}
.user-badge {
    white-space: nowrap;
}

.admin-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.admin-tabs-label {
    white-space: nowrap;
}
.table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.table-wrap table {
    min-width: 760px;
}
@media (max-width: 900px) {
    .admin-tabs {
        align-items: stretch;
    }
    .admin-tabs-label {
        width: 100%;
    }
    .admin-tabs a {
        flex: 1 1 auto;
        justify-content: center;
        text-align: center;
    }
    .hr-filter-toolbar {
        gap: 12px;
    }
    .hr-filter-search-group input[type="search"] {
        min-height: 42px;
    }
}
@media (max-width: 520px) {
    .card.card-wide {
        padding: 16px;
    }
    .admin-tabs a {
        width: 100%;
    }
    .hr-filter-toolbar-main h2 {
        margin-top: 0;
    }
    .table-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
        align-items: stretch;
    }
    .table-actions .btn {
        width: 100%;
        justify-content: center;
        white-space: nowrap;
    }
}

@media (max-width: 900px) {
    .hr-filter-toolbar {
        grid-template-columns: 1fr;
    }
    .admin-page-actions .btn {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="admin-tabs">
        <span class="admin-tabs-label">Sezione:</span>
        <a class="active" href="utenti.php"><i class="la la-users" aria-hidden="true"></i> Utenti</a>
        <a href="ruoli_utenti.php"><i class="la la-user-tag" aria-hidden="true"></i> Ruoli utenti</a>
        <a href="permessi_ruoli.php"><i class="la la-key" aria-hidden="true"></i> Permessi ruoli</a>
    </div>

    <div class="hr-filter-toolbar">
        <div class="hr-filter-toolbar-main">
            <h2 style="margin-bottom:.35rem;">Gestione utenti</h2>
            <p class="muted" style="margin:0;">Elenco degli utenti censiti nel portale.</p>
            <div class="admin-page-actions">
                <a class="btn btn-primary" href="utente_nuovo.php"><i class="la la-user-plus" aria-hidden="true"></i> Nuovo utente</a>
            </div>
        </div>
        <div class="form-group hr-filter-search-group">
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
                            <span class="status-badge user-badge <?= classeBadgeUtente('ok') ?>">Attivo</span>
                        <?php else: ?>
                            <span class="status-badge user-badge <?= classeBadgeUtente('neutral') ?>">Disattivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$utente['deve_cambiare_password'] === 1): ?>
                            <span class="status-badge user-badge <?= classeBadgeUtente('wait') ?>">Obbligatorio</span>
                        <?php else: ?>
                            <span class="status-badge user-badge <?= classeBadgeUtente('ok') ?>">No</span>
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

<script>
(function () {
    function normalizzaTesto(valore) {
        return (valore || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var tableId = input.getAttribute('data-table-filter');
        var table = document.getElementById(tableId);
        if (!table) {
            return;
        }

        input.addEventListener('input', function () {
            var filtro = normalizzaTesto(input.value);
            table.querySelectorAll('tbody tr').forEach(function (row) {
                var testo = normalizzaTesto(row.textContent);
                row.style.display = testo.indexOf(filtro) !== -1 ? '' : 'none';
            });
        });
    });
})();
</script>

<?php layoutFooter(); ?>
