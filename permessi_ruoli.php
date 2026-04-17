<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoScrittura('utenti');

$errore = '';
$messaggio = '';
$livelliValidi = ['none', 'read', 'write'];

try {
    $stmtRuoli = $pdo->query("
        SELECT id_ruolo, codice_ruolo, descrizione, attivo, ordinamento
        FROM aut_ruoli
        WHERE attivo = 1
        ORDER BY ordinamento, codice_ruolo
    ");
    $ruoli = $stmtRuoli->fetchAll();

    $stmtRisorse = $pdo->query("
        SELECT id_risorsa, codice_risorsa, descrizione, tipo_risorsa, attivo, ordinamento
        FROM aut_risorse
        WHERE attivo = 1
          AND tipo_risorsa = 'pagina'
        ORDER BY ordinamento, codice_risorsa
    ");
    $risorse = $stmtRisorse->fetchAll();
} catch (Throwable $e) {
    http_response_code(500);
    die('Errore nel caricamento di ruoli o risorse.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        foreach ($ruoli as $ruolo) {
            $idRuolo = (int)$ruolo['id_ruolo'];

            foreach ($risorse as $risorsa) {
                $idRisorsa = (int)$risorsa['id_risorsa'];
                $chiave = 'permesso_ruolo_' . $idRuolo . '_' . $idRisorsa;
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
                    'id_ruolo' => $idRuolo,
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
                    'id_ruolo' => $idRuolo,
                    'id_risorsa' => $idRisorsa,
                    'consentito' => $editConsentito,
                ]);
            }
        }

        $pdo->commit();
        $messaggio = 'Permessi ruoli aggiornati correttamente.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errore = 'Errore durante il salvataggio dei permessi ruoli.';
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
        I permessi vengono assegnati ai ruoli e applicati alle risorse di tipo pagina.
    </div>

    <?php if ($errore !== ''): ?>
        <div class="errore"><?= htmlspecialchars($errore) ?></div>
    <?php endif; ?>

    <?php if ($messaggio !== ''): ?>
        <div class="ok"><?= htmlspecialchars($messaggio) ?></div>
    <?php endif; ?>

    <form method="post">
        <table>
            <thead>
                <tr>
                    <th>Ruolo</th>
                    <?php foreach ($risorse as $risorsa): ?>
                        <th><?= htmlspecialchars((string)$risorsa['descrizione']) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ruoli as $ruolo): ?>
                    <?php $idRuolo = (int)$ruolo['id_ruolo']; ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string)$ruolo['codice_ruolo']) ?></strong></td>

                        <?php foreach ($risorse as $risorsa): ?>
                            <?php
                            $idRisorsa = (int)$risorsa['id_risorsa'];
                            $chiave = 'permesso_ruolo_' . $idRuolo . '_' . $idRisorsa;
                            $livelloCorrente = livelloCorrenteRuolo($permessiRuoliMappa, $idRuolo, $idRisorsa);
                            ?>
                            <td>
                                <select name="<?= htmlspecialchars($chiave) ?>">
                                    <option value="none" <?= $livelloCorrente === 'none' ? 'selected' : '' ?>>none</option>
                                    <option value="read" <?= $livelloCorrente === 'read' ? 'selected' : '' ?>>read</option>
                                    <option value="write" <?= $livelloCorrente === 'write' ? 'selected' : '' ?>>write</option>
                                </select>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="actions">
            <button type="submit">Salva permessi ruoli</button>
        </div>
    </form>

    <div class="links">
        <a href="index.php">Torna alla dashboard</a>
    </div>
</div>

<?php layoutFooter(); ?>
