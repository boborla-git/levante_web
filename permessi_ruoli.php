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

<div class="card card-wide">
    <h1>Admin</h1>

    <div class="links" style="margin-bottom:18px;">
        <strong>Sezione:</strong>
        <a href="utenti.php">Utenti</a>
        &nbsp;|&nbsp;
        <a href="ruoli_utenti.php">Ruoli utenti</a>
        &nbsp;|&nbsp;
        <a href="permessi_ruoli.php"><strong>Permessi ruoli</strong></a>
    </div>

    <h2>Permessi ruoli</h2>

    <div class="meta" style="margin-bottom:18px;">
        Gestione permessi su risorse gerarchiche del portale.
        Le righe rappresentano l'albero di <code>aut_risorse</code>.
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="get" id="formRuolo" style="margin-bottom:20px;">
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

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Risorsa</th>
                        <th style="text-align:center;">None</th>
                        <th style="text-align:center;">Read</th>
                        <th style="text-align:center;">Write</th>
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
                                <td colspan="3" style="text-align:center; color:#64748b;">automatico</td>
                            <?php else: ?>
                                <td style="text-align:center;">
                                    <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="none" <?= $livelloCorrente === 'none' ? 'checked' : '' ?>>
                                </td>
                                <td style="text-align:center;">
                                    <input type="radio" name="<?= htmlspecialchars($chiave) ?>" value="read" <?= $livelloCorrente === 'read' ? 'checked' : '' ?>>
                                </td>
                                <td style="text-align:center;">
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

        <div class="actions">
            <button type="submit">Salva permessi ruolo</button>
        </div>
    </form>
</div>

<?php layoutFooter(); ?>
