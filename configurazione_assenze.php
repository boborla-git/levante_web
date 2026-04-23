<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$errore = '';
$messaggio = '';
$tipologie = [];
$configurazioni = [];
$riepilogo = [
    'tipologie_attive' => 0,
    'relazioni_attive' => 0,
    'gruppi_attivi' => 0,
    'membri_gruppi_attivi' => 0,
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrPaletteColori(): array
{
    return [
        '#28a745' => 'Verde',
        '#17a2b8' => 'Azzurro',
        '#6f42c1' => 'Viola',
        '#ffc107' => 'Giallo',
        '#dc3545' => 'Rosso',
        '#fd7e14' => 'Arancio',
        '#0d6efd' => 'Blu',
        '#20c997' => 'Turchese',
        '#6c757d' => 'Grigio',
        '#343a40' => 'Antracite',
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));

        if ($azione === 'salva_tipologia') {
            $id = (int)($_POST['id_tipologia_evento'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Tipologia non valida.');
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_tipologie_evento
                 SET richiede_approvazione = :richiede_approvazione,
                     consente_giorni = :consente_giorni,
                     consente_ore = :consente_ore,
                     visibile_calendario = :visibile_calendario,
                     visibile_ai_colleghi = :visibile_ai_colleghi,
                     attivo = :attivo,
                     ordinamento = :ordinamento,
                     colore_calendario = :colore_calendario
                 WHERE id_tipologia_evento = :id_tipologia_evento'
            );
            $stmt->execute([
                'richiede_approvazione' => isset($_POST['richiede_approvazione']) ? 1 : 0,
                'consente_giorni' => isset($_POST['consente_giorni']) ? 1 : 0,
                'consente_ore' => isset($_POST['consente_ore']) ? 1 : 0,
                'visibile_calendario' => isset($_POST['visibile_calendario']) ? 1 : 0,
                'visibile_ai_colleghi' => isset($_POST['visibile_ai_colleghi']) ? 1 : 0,
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'ordinamento' => (int)($_POST['ordinamento'] ?? 0),
                'colore_calendario' => trim((string)($_POST['colore_calendario'] ?? '')) !== '' ? trim((string)($_POST['colore_calendario'] ?? '')) : null,
                'id_tipologia_evento' => $id,
            ]);

            header('Location: configurazione_assenze.php?ok_tipologia=1');
            exit;
        }

        if ($azione === 'salva_configurazione') {
            $id = (int)($_POST['id_configurazione'] ?? 0);
            if ($id <= 0) {
                throw new RuntimeException('Configurazione non valida.');
            }

            $stmt = $pdo->prepare('UPDATE hr_configurazioni SET valore = :valore, attivo = :attivo WHERE id_configurazione = :id_configurazione');
            $stmt->execute([
                'valore' => trim((string)($_POST['valore'] ?? '')),
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'id_configurazione' => $id,
            ]);

            header('Location: configurazione_assenze.php?ok_config=1');
            exit;
        }
    }

    if (isset($_GET['ok_tipologia'])) {
        $messaggio = 'Tipologia aggiornata correttamente.';
    } elseif (isset($_GET['ok_config'])) {
        $messaggio = 'Configurazione aggiornata correttamente.';
    }

    $riepilogo['tipologie_attive'] = (int)$pdo->query('SELECT COUNT(*) FROM hr_tipologie_evento WHERE attivo = 1')->fetchColumn();
    $riepilogo['relazioni_attive'] = (int)$pdo->query('SELECT COUNT(*) FROM hr_relazioni_organizzative WHERE attiva = 1 AND (data_fine IS NULL OR data_fine >= CURDATE())')->fetchColumn();
    $riepilogo['gruppi_attivi'] = (int)$pdo->query('SELECT COUNT(*) FROM hr_gruppi_lavoro WHERE attivo = 1')->fetchColumn();
    $riepilogo['membri_gruppi_attivi'] = (int)$pdo->query('SELECT COUNT(*) FROM hr_gruppi_utenti WHERE attivo = 1 AND (data_fine IS NULL OR data_fine >= CURDATE())')->fetchColumn();

    $tipologie = $pdo->query(
        'SELECT te.*, sp.descrizione AS stato_presenza
         FROM hr_tipologie_evento te
         INNER JOIN hr_stati_presenza sp ON sp.id_stato_presenza = te.id_stato_presenza
         ORDER BY te.ordinamento, te.descrizione'
    )->fetchAll();

    $configurazioni = $pdo->query('SELECT * FROM hr_configurazioni ORDER BY codice')->fetchAll();
} catch (Throwable $e) {
    $errore = $e->getMessage();
}

layoutHeader('Configurazione assenze');
?>
<div class="card card-compact">
    <div class="section-head">
        <div>
            <h1>Configurazione assenze</h1>
            <div class="meta">Da qui governi il modulo HR: tipologie, relazioni organizzative, gruppi di lavoro e impostazioni principali.</div>
        </div>
        <div class="section-head-actions">
            <a class="btn btn-light" href="relazioni_organizzative.php">Relazioni organizzative</a>
            <a class="btn btn-light" href="gruppi_lavoro.php">Gruppi di lavoro</a>
            <a class="btn btn-light" href="assenze.php">Vai ad assenze</a>
        </div>
    </div>
</div>

<?php if ($errore !== ''): ?><div class="errore"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="ok"><?= h($messaggio) ?></div><?php endif; ?>

<div class="dashboard-grid" style="margin-bottom:22px;">
    <div class="dashboard-box"><h3>Tipologie attive</h3><div class="kpi-number"><?= $riepilogo['tipologie_attive'] ?></div></div>
    <div class="dashboard-box"><h3>Relazioni attive</h3><div class="kpi-number"><?= $riepilogo['relazioni_attive'] ?></div></div>
    <div class="dashboard-box"><h3>Gruppi attivi</h3><div class="kpi-number"><?= $riepilogo['gruppi_attivi'] ?></div></div>
    <div class="dashboard-box"><h3>Appartenenze attive</h3><div class="kpi-number"><?= $riepilogo['membri_gruppi_attivi'] ?></div></div>
</div>

<div class="card card-wide">
    <h2>Tipologie evento</h2>
    <div class="meta" style="margin-bottom:16px;">La colonna colore ora usa una palette guidata: il colore scelto viene mostrato nel calendario e nel popup di dettaglio.</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Codice</th>
                    <th>Descrizione</th>
                    <th>Presenza</th>
                    <th>Regole</th>
                    <th>Visibilità</th>
                    <th>Ordine / colore</th>
                    <th>Salva</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tipologie as $t): ?>
                <tr>
                    <form method="post" action="configurazione_assenze.php">
                        <input type="hidden" name="azione" value="salva_tipologia">
                        <input type="hidden" name="id_tipologia_evento" value="<?= (int)$t['id_tipologia_evento'] ?>">
                        <td><strong><?= h((string)$t['codice']) ?></strong></td>
                        <td><?= h((string)$t['descrizione']) ?></td>
                        <td><?= h((string)$t['stato_presenza']) ?></td>
                        <td>
                            <label class="checkbox-inline"><input type="checkbox" name="richiede_approvazione" value="1" <?= (int)$t['richiede_approvazione'] === 1 ? 'checked' : '' ?>> approvazione</label><br>
                            <label class="checkbox-inline"><input type="checkbox" name="consente_giorni" value="1" <?= (int)$t['consente_giorni'] === 1 ? 'checked' : '' ?>> giorni</label><br>
                            <label class="checkbox-inline"><input type="checkbox" name="consente_ore" value="1" <?= (int)$t['consente_ore'] === 1 ? 'checked' : '' ?>> ore</label>
                        </td>
                        <td>
                            <label class="checkbox-inline"><input type="checkbox" name="visibile_calendario" value="1" <?= (int)$t['visibile_calendario'] === 1 ? 'checked' : '' ?>> calendario</label><br>
                            <label class="checkbox-inline"><input type="checkbox" name="visibile_ai_colleghi" value="1" <?= (int)$t['visibile_ai_colleghi'] === 1 ? 'checked' : '' ?>> colleghi</label><br>
                            <label class="checkbox-inline"><input type="checkbox" name="attivo" value="1" <?= (int)$t['attivo'] === 1 ? 'checked' : '' ?>> attiva</label>
                        </td>
                        <td>
                            <input type="number" name="ordinamento" value="<?= (int)$t['ordinamento'] ?>" style="width:90px; margin-bottom:8px;"><br>
                            <select name="colore_calendario" style="width:170px; margin-bottom:8px;">
                                <option value="">Nessun colore</option>
                                <?php foreach (hrPaletteColori() as $hex => $label): ?>
                                    <option value="<?= h($hex) ?>" <?= (string)$t['colore_calendario'] === (string)$hex ? 'selected' : '' ?>>
                                        <?= h($label) ?> · <?= h($hex) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="color-swatch-row">
                                <?php foreach (hrPaletteColori() as $hex => $label): ?>
                                    <span class="color-swatch <?= (string)$t['colore_calendario'] === (string)$hex ? 'active' : '' ?>" title="<?= h($label) ?>" style="background: <?= h($hex) ?>;"></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td><button type="submit" class="btn-light">Salva</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card card-wide">
    <h2>Configurazioni tecniche</h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Codice</th><th>Descrizione</th><th>Valore</th><th>Attiva</th><th>Salva</th></tr></thead>
            <tbody>
            <?php foreach ($configurazioni as $c): ?>
                <tr>
                    <form method="post" action="configurazione_assenze.php">
                        <input type="hidden" name="azione" value="salva_configurazione">
                        <input type="hidden" name="id_configurazione" value="<?= (int)$c['id_configurazione'] ?>">
                        <td><strong><?= h((string)$c['codice']) ?></strong></td>
                        <td><?= h((string)$c['descrizione']) ?></td>
                        <td><input type="text" name="valore" value="<?= h((string)$c['valore']) ?>"></td>
                        <td><label class="checkbox-inline"><input type="checkbox" name="attivo" value="1" <?= (int)$c['attivo'] === 1 ? 'checked' : '' ?>> sì</label></td>
                        <td><button type="submit" class="btn-light">Salva</button></td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php layoutFooter(); ?>
