<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';
$messaggio = '';
$livelliValidi = ['none', 'read', 'write'];

function livelloCorrenteRuolo(array $permessiRuoliMappa, int $idRuolo, int $idRisorsa): string
{
    $view = (int)($permessiRuoliMappa[$idRuolo][$idRisorsa]['view'] ?? 0);
    $edit = (int)($permessiRuoliMappa[$idRuolo][$idRisorsa]['edit'] ?? 0);

    if ($view === 1 && $edit === 1) {
        return 'write';
    }

    if ($view === 1) {
        return 'read';
    }

    return 'none';
}

function appiattisciAlberoRisorse(array $nodiPerPadre, ?int $idPadre = null, int $depth = 0): array
{
    $output = [];

    if (!isset($nodiPerPadre[$idPadre])) {
        return $output;
    }

    foreach ($nodiPerPadre[$idPadre] as $nodo) {
        $nodo['depth'] = $depth;
        $output[] = $nodo;

        foreach (appiattisciAlberoRisorse($nodiPerPadre, (int)$nodo['id_risorsa'], $depth + 1) as $figlio) {
            $output[] = $figlio;
        }
    }

    return $output;
}

function risorsaContenitorePuro(array $risorsa): bool
{
    $tipo = trim((string)($risorsa['tipo_risorsa'] ?? ''));
    $percorso = trim((string)($risorsa['percorso'] ?? ''));

    return $tipo === 'menu' && $percorso === '';
}

try {
    $stmtRuoli = $pdo->query("
        SELECT id_ruolo, codice_ruolo, descrizione, attivo, ordinamento
        FROM aut_ruoli
        WHERE attivo = 1
        ORDER BY ordinamento, codice_ruolo
    ");
    $ruoli = $stmtRuoli->fetchAll();

    if (!$ruoli) {
        throw new RuntimeException('Nessun ruolo attivo trovato.');
    }

    $stmtRisorse = $pdo->query("
        SELECT
            id_risorsa,
            codice_risorsa,
            descrizione,
            tipo_risorsa,
            id_risorsa_padre,
            percorso,
            visibile_menu,
            ordinamento,
            attivo
        FROM aut_risorse
        WHERE attivo = 1
        ORDER BY
            COALESCE(id_risorsa_padre, 0),
            ordinamento,
            codice_risorsa
    ");
    $risorse = $stmtRisorse->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di ruoli o risorse.');
}

$ruoliPerId = [];
foreach ($ruoli as $ruolo) {
    $ruoliPerId[(int)$ruolo['id_ruolo']] = $ruolo;
}

$idRuoloSelezionato = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idRuoloSelezionato = (int)($_POST['id_ruolo'] ?? 0);
} else {
    $idRuoloSelezionato = (int)($_GET['id_ruolo'] ?? 0);
}

if ($idRuoloSelezionato <= 0 || !isset($ruoliPerId[$idRuoloSelezionato])) {
    $primoRuolo = reset($ruoli);
    $idRuoloSelezionato = (int)$primoRuolo['id_ruolo'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salva_permessi'])) {
    try {
        $pdo->beginTransaction();

        foreach ($risorse as $risorsa) {
            $idRisorsa = (int)$risorsa['id_risorsa'];

            if (risorsaContenitorePuro($risorsa)) {
                continue;
            }

            $chiave = 'permesso_risorsa_' . $idRisorsa;
            $livello = (string)($_POST[$chiave] ?? 'none');

            if (!in_array($livello, $livelliValidi, true)) {
                $livello = 'none';
            }

            $viewConsentito = ($livello === 'read' || $livello === 'write') ? 1 : 0;
            $editConsentito = ($livello === 'write') ? 1 : 0;

            $stmtView = $pdo->prepare("
                INSERT INTO aut_ruoli_permessi
                (
                    id_ruolo,
                    id_risorsa,
                    permesso,
                    consentito
                )
                VALUES
                (
                    :id_ruolo,
                    :id_risorsa,
                    'view',
                    :consentito
                )
                ON DUPLICATE KEY UPDATE
                    consentito = VALUES(consentito)
            ");
            $stmtView->execute([
                'id_ruolo' => $idRuoloSelezionato,
                'id_risorsa' => $idRisorsa,
                'consentito' => $viewConsentito,
            ]);

            $stmtEdit = $pdo->prepare("
                INSERT INTO aut_ruoli_permessi
                (
                    id_ruolo,
                    id_risorsa,
                    permesso,
                    consentito
                )
                VALUES
                (
                    :id_ruolo,
                    :id_risorsa,
                    'edit',
                    :consentito
                )
                ON DUPLICATE KEY UPDATE
                    consentito = VALUES(consentito)
            ");
            $stmtEdit->execute([
                'id_ruolo' => $idRuoloSelezionato,
                'id_risorsa' => $idRisorsa,
                'consentito' => $editConsentito,
            ]);
        }

        $pdo->commit();
        $messaggio = 'Permessi del ruolo aggiornati correttamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errore = 'Errore durante il salvataggio dei permessi del ruolo.';
        error_log('permessi_ruoli.php save error: ' . $e->getMessage());
    }
}

$permessiRuoliMappa = [];

try {
    $stmtPermessi = $pdo->query("
        SELECT id_ruolo, id_risorsa, permesso, consentito
        FROM aut_ruoli_permessi
    ");

    while ($riga = $stmtPermessi->fetch()) {
        $idRuolo = (int)$riga['id_ruolo'];
        $idRisorsa = (int)$riga['id_risorsa'];
        $permesso = (string)$riga['permesso'];
        $consentito = (int)$riga['consentito'];

        $permessiRuoliMappa[$idRuolo][$idRisorsa][$permesso] = $consentito;
    }
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento dei permessi dei ruoli.');
}

$nodiPerPadre = [];
foreach ($risorse as $risorsa) {
    $idPadre = null;

    if (isset($risorsa['id_risorsa_padre']) && $risorsa['id_risorsa_padre'] !== null) {
        $idPadre = (int)$risorsa['id_risorsa_padre'];
        if ($idPadre === 0) {
            $idPadre = null;
        }
    }

    $nodiPerPadre[$idPadre][] = $risorsa;
}

foreach ($nodiPerPadre as $idPadre => $figli) {
    usort($nodiPerPadre[$idPadre], static function (array $a, array $b): int {
        $ordineA = (int)($a['ordinamento'] ?? 0);
        $ordineB = (int)($b['ordinamento'] ?? 0);

        if ($ordineA === $ordineB) {
            return strcmp((string)$a['codice_risorsa'], (string)$b['codice_risorsa']);
        }

        return $ordineA <=> $ordineB;
    });
}

$risorseGerarchiche = appiattisciAlberoRisorse($nodiPerPadre);
$ruoloSelezionato = $ruoliPerId[$idRuoloSelezionato];

layoutHeader('Permessi ruoli');
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
.permissions-role-form {
    display: grid;
    grid-template-columns: minmax(260px, 1fr);
    gap: 8px;
    margin-bottom: 16px;
}
.permissions-role-form label {
    margin: 0;
}
.permissions-role-form select {
    width: 100%;
    min-height: 40px;
}
.permissions-table td,
.permissions-table th {
    vertical-align: middle;
}
.permissions-radio-cell {
    text-align: center;
    white-space: nowrap;
}
.permissions-auto-cell {
    text-align: center;
    color: #64748b;
}
.admin-save-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
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
    min-width: 920px;
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
    .admin-save-actions .btn,
    .admin-save-actions button {
        width: 100%;
        justify-content: center;
    }
}
</style>

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="admin-tabs">
        <span class="admin-tabs-label">Sezione:</span>
        <a href="utenti.php"><i class="la la-users" aria-hidden="true"></i> Utenti</a>
        <a href="ruoli_utenti.php"><i class="la la-user-tag" aria-hidden="true"></i> Ruoli utenti</a>
        <a class="active" href="permessi_ruoli.php"><i class="la la-key" aria-hidden="true"></i> Permessi ruoli</a>
    </div>

    <div class="hr-filter-toolbar">
        <div class="hr-filter-toolbar-main">
            <h2 style="margin-bottom:.35rem;">Permessi ruoli</h2>
            <p class="muted" style="margin:0;">
                Gestione permessi su risorse gerarchiche del portale. Le righe rappresentano l'albero di <code>aut_risorse</code>.
            </p>
        </div>
        <div class="form-group hr-filter-search-group">
            <label for="filtroRapidoPermessiRuoli">Filtro rapido</label>
            <input
                type="search"
                id="filtroRapidoPermessiRuoli"
                placeholder="Cerca in tutte le colonne..."
                autocomplete="off"
                data-table-filter="tabellaPermessiRuoli"
            >
        </div>
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="get" id="formRuolo" class="permissions-role-form">
        <label for="id_ruolo"><strong>Ruolo da gestire:</strong></label>
        <select name="id_ruolo" id="id_ruolo" onchange="this.form.submit()">
            <?php foreach ($ruoli as $ruolo): ?>
                <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= (int)$ruolo['id_ruolo'] === $idRuoloSelezionato ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$ruolo['codice_ruolo']) ?> - <?= htmlspecialchars((string)$ruolo['descrizione']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="meta" style="margin-bottom:18px;">
        <strong>Ruolo corrente:</strong>
        <?= htmlspecialchars((string)$ruoloSelezionato['codice_ruolo']) ?>
        - <?= htmlspecialchars((string)$ruoloSelezionato['descrizione']) ?>
    </div>

    <form method="post">
        <input type="hidden" name="id_ruolo" value="<?= (int)$idRuoloSelezionato ?>">
        <input type="hidden" name="salva_permessi" value="1">

        <div class="table-responsive table-wrap">
            <table id="tabellaPermessiRuoli" class="permissions-table">
                <thead>
                    <tr>
                        <th>Risorsa</th>
                        <th class="permissions-radio-cell">None</th>
                        <th class="permissions-radio-cell">Read</th>
                        <th class="permissions-radio-cell">Write</th>
                        <th>Tipo</th>
                        <th>Percorso</th>
                        <th>Codice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($risorseGerarchiche as $risorsa): ?>
                        <?php
                        $idRisorsa = (int)$risorsa['id_risorsa'];
                        $livelloCorrente = livelloCorrenteRuolo($permessiRuoliMappa, $idRuoloSelezionato, $idRisorsa);
                        $depth = (int)($risorsa['depth'] ?? 0);
                        $padding = 12 + ($depth * 28);
                        $percorso = trim((string)($risorsa['percorso'] ?? ''));
                        $chiave = 'permesso_risorsa_' . $idRisorsa;
                        $tipo = trim((string)($risorsa['tipo_risorsa'] ?? ''));
                        $prefisso = $depth > 0 ? str_repeat('↳ ', $depth) : '';
                        $contenitorePuro = risorsaContenitorePuro($risorsa);
                        ?>
                        <tr>
                            <td style="padding-left: <?= $padding ?>px; white-space: nowrap;">
                                <?= htmlspecialchars($prefisso) ?>
                                <strong><?= htmlspecialchars((string)$risorsa['descrizione']) ?></strong>
                            </td>

                            <?php if ($contenitorePuro): ?>
                                <td colspan="3" class="permissions-auto-cell">automatico</td>
                            <?php else: ?>
                                <td class="permissions-radio-cell">
                                    <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="none" <?= $livelloCorrente === 'none' ? 'checked' : '' ?>>
                                </td>
                                <td class="permissions-radio-cell">
                                    <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="read" <?= $livelloCorrente === 'read' ? 'checked' : '' ?>>
                                </td>
                                <td class="permissions-radio-cell">
                                    <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="write" <?= $livelloCorrente === 'write' ? 'checked' : '' ?>>
                                </td>
                            <?php endif; ?>

                            <td><?= htmlspecialchars($tipo) ?></td>
                            <td><?= $percorso !== '' ? '<code>' . htmlspecialchars($percorso) . '</code>' : '&mdash;' ?></td>
                            <td><code><?= htmlspecialchars((string)$risorsa['codice_risorsa']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-save-actions">
            <button class="btn btn-primary" type="submit"><i class="la la-save" aria-hidden="true"></i> Salva permessi ruolo</button>
        </div>
    </form>
</div>

<script>
(function () {
    function normalizzaTesto(valore) {
        return (valore || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');
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
