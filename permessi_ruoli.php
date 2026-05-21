<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/admin.php';

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

function adminPermissionLevelLabel(string $level): string
{
    if ($level === 'write') {
        return 'Scrittura';
    }

    if ($level === 'read') {
        return 'Lettura';
    }

    return 'Nessuno';
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

$totaleRisorse = count($risorseGerarchiche);
$totaleContenitori = 0;
$totaleRisorseProtette = 0;
$totaleVisibiliMenu = 0;
$totaleNessuno = 0;
$totaleLettura = 0;
$totaleScrittura = 0;

foreach ($risorseGerarchiche as $risorsaConteggio) {
    if (risorsaContenitorePuro($risorsaConteggio)) {
        $totaleContenitori++;
        continue;
    }

    $totaleRisorseProtette++;
    $livelloConteggio = livelloCorrenteRuolo($permessiRuoliMappa, $idRuoloSelezionato, (int)$risorsaConteggio['id_risorsa']);
    if ($livelloConteggio === 'write') {
        $totaleScrittura++;
    } elseif ($livelloConteggio === 'read') {
        $totaleLettura++;
    } else {
        $totaleNessuno++;
    }

    if ((int)($risorsaConteggio['visibile_menu'] ?? 0) === 1) {
        $totaleVisibiliMenu++;
    }
}

layoutHeader('Permessi ruoli');
?>
<link rel="stylesheet" href="/assets/admin.css">

<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Permessi ruoli</h1>
            <div class="meta">Gestione dei permessi associati ai ruoli del portale: accesso negato, sola lettura o scrittura.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="index.php"><i class="la la-arrow-left" aria-hidden="true"></i> Dashboard</a>
        </div>
    </div>
</div>

<div class="card card-compact">
    <?php renderAdminTabs('permessi_ruoli'); ?>
</div>

<section class="hr-config-summary">
    <span><strong><?= (int)$totaleRisorseProtette ?></strong> risorse protette</span>
    <span><strong><?= (int)$totaleScrittura ?></strong> in scrittura</span>
    <span><strong><?= (int)$totaleLettura ?></strong> in lettura</span>
    <span><strong><?= (int)$totaleNessuno ?></strong> senza accesso</span>
    <span><strong><?= (int)$totaleContenitori ?></strong> contenitori</span>
</section>

<?php renderAdminAlert($errore, 'danger'); ?>
<?php renderAdminAlert($messaggio, 'success'); ?>

<div class="card card-wide admin-permissions-card">
    <div class="hr-filter-toolbar admin-section-toolbar">
        <div class="admin-section-title">
            <h2>Ruolo selezionato</h2>
            <div class="meta">Scegli il ruolo da configurare. Le modifiche vengono salvate solo con il pulsante finale.</div>
        </div>

        <form method="get" id="formRuolo" class="permissions-role-form">
            <label for="id_ruolo">Ruolo da gestire</label>
            <select name="id_ruolo" id="id_ruolo" onchange="this.form.submit()">
                <?php foreach ($ruoli as $ruolo): ?>
                    <option value="<?= (int)$ruolo['id_ruolo'] ?>" <?= (int)$ruolo['id_ruolo'] === $idRuoloSelezionato ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string)$ruolo['descrizione']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="admin-current-role">
        <span><strong>Ruolo corrente:</strong> <?= htmlspecialchars((string)$ruoloSelezionato['descrizione']) ?></span>
        <span><strong>Codice tecnico:</strong> <code><?= htmlspecialchars((string)$ruoloSelezionato['codice_ruolo']) ?></code></span>
        <span><strong>Risorse visibili nel menu:</strong> <?= (int)$totaleVisibiliMenu ?></span>
    </div>
</div>

<form method="post">
    <input type="hidden" name="id_ruolo" value="<?= (int)$idRuoloSelezionato ?>">
    <input type="hidden" name="salva_permessi" value="1">

    <div class="card card-wide admin-permissions-card">
        <div class="hr-filter-toolbar admin-section-toolbar">
            <div class="admin-section-title">
                <h2>Albero permessi</h2>
                <div class="meta">Vista gerarchica delle risorse del portale. I contenitori organizzano il menu e non hanno permessi propri.</div>
            </div>

            <div class="form-group hr-filter-search-group">
                <label for="filtroRapidoPermessiRuoli">Filtro rapido</label>
                <input type="search" id="filtroRapidoPermessiRuoli" placeholder="Cerca risorsa, percorso, codice..." autocomplete="off">
            </div>
        </div>

        <div class="table-responsive table-wrap admin-permissions-table-wrap">
            <table id="tabellaPermessiRuoli" class="permissions-table">
                <thead>
                    <tr>
                        <th>Risorsa</th>
                        <th class="permissions-radio-cell">Nessuno</th>
                        <th class="permissions-radio-cell">Lettura</th>
                        <th class="permissions-radio-cell">Scrittura</th>
                        <th>Tipo</th>
                        <th>Percorso</th>
                        <th>Menu</th>
                        <th>Codice</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($risorseGerarchiche as $risorsa): ?>
                        <?php
                        $idRisorsa = (int)$risorsa['id_risorsa'];
                        $livelloCorrente = livelloCorrenteRuolo($permessiRuoliMappa, $idRuoloSelezionato, $idRisorsa);
                        $depth = (int)($risorsa['depth'] ?? 0);
                        $percorso = trim((string)($risorsa['percorso'] ?? ''));
                        $chiave = 'permesso_risorsa_' . $idRisorsa;
                        $tipo = trim((string)($risorsa['tipo_risorsa'] ?? ''));
                        $prefisso = $depth > 0 ? str_repeat('↳ ', $depth) : '';
                        $depthClass = 'resource-depth-' . min($depth, 8);
                        $contenitorePuro = risorsaContenitorePuro($risorsa);
                        $visibileMenu = (int)($risorsa['visibile_menu'] ?? 0) === 1;
                        $searchText = trim((string)$risorsa['descrizione'] . ' ' . (string)$risorsa['codice_risorsa'] . ' ' . $tipo . ' ' . $percorso . ' ' . adminPermissionLevelLabel($livelloCorrente));
                        ?>
                        <tr class="<?= $contenitorePuro ? 'permissions-row-container' : 'permissions-row-resource' ?>" data-search="<?= htmlspecialchars(mb_strtolower($searchText, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>">
                            <td class="permissions-resource-cell <?= htmlspecialchars($depthClass) ?>">
                                <span class="permissions-tree-prefix"><?= htmlspecialchars($prefisso) ?></span>
                                <strong><?= htmlspecialchars((string)$risorsa['descrizione']) ?></strong>
                                <span class="permissions-mobile-meta"><?= htmlspecialchars(adminPermissionLevelLabel($livelloCorrente)) ?></span>
                            </td>

                            <?php if ($contenitorePuro): ?>
                                <td colspan="3" class="permissions-auto-cell">Contenitore menu</td>
                            <?php else: ?>
                                <td class="permissions-radio-cell">
                                    <label class="permission-choice" title="Nessun accesso">
                                        <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="none" aria-label="Nessun accesso per <?= htmlspecialchars((string)$risorsa['descrizione']) ?>" <?= $livelloCorrente === 'none' ? 'checked' : '' ?>>
                                    </label>
                                </td>
                                <td class="permissions-radio-cell">
                                    <label class="permission-choice" title="Solo lettura">
                                        <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="read" aria-label="Solo lettura per <?= htmlspecialchars((string)$risorsa['descrizione']) ?>" <?= $livelloCorrente === 'read' ? 'checked' : '' ?>>
                                    </label>
                                </td>
                                <td class="permissions-radio-cell">
                                    <label class="permission-choice" title="Lettura e scrittura">
                                        <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="write" aria-label="Lettura e scrittura per <?= htmlspecialchars((string)$risorsa['descrizione']) ?>" <?= $livelloCorrente === 'write' ? 'checked' : '' ?>>
                                    </label>
                                </td>
                            <?php endif; ?>

                            <td><?= htmlspecialchars($tipo) ?></td>
                            <td><?= $percorso !== '' ? '<code>' . htmlspecialchars($percorso) . '</code>' : '&mdash;' ?></td>
                            <td>
                                <span class="resource-menu-badge <?= $visibileMenu ? 'resource-menu-badge-on' : 'resource-menu-badge-off' ?>">
                                    <?= $visibileMenu ? 'Sì' : 'No' ?>
                                </span>
                            </td>
                            <td><code><?= htmlspecialchars((string)$risorsa['codice_risorsa']) ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php renderAdminSaveActions('Salva permessi ruolo'); ?>
    </div>
</form>

<script>
(function () {
    function normalize(value) {
        return (value || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    var input = document.getElementById('filtroRapidoPermessiRuoli');
    var table = document.getElementById('tabellaPermessiRuoli');
    if (!input || !table) return;

    input.addEventListener('input', function () {
        var filter = normalize(input.value);
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var text = normalize(row.getAttribute('data-search') || row.textContent);
            row.style.display = text.indexOf(filter) !== -1 ? '' : 'none';
        });
    });
})();
</script>

<?php layoutFooter(); ?>
