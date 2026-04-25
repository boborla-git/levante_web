<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('configurazione_assenze');

$pdo = db();
$puoScrivere = haPermessoScrittura('configurazione_assenze');
$messaggio = '';
$errore = '';
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

function hrColoreValido(?string $colore): string
{
    $colore = trim((string)$colore);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $colore) ? $colore : '#6c757d';
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

            $descrizione = trim((string)($_POST['descrizione'] ?? ''));
            $descrizioneCalendario = trim((string)($_POST['descrizione_calendario'] ?? ''));
            $coloreCalendario = hrColoreValido((string)($_POST['colore_calendario'] ?? ''));

            if ($descrizione === '') {
                throw new RuntimeException('La descrizione della tipologia è obbligatoria.');
            }
            if ($descrizioneCalendario === '') {
                $descrizioneCalendario = $descrizione;
            }

            $stmt = $pdo->prepare(
                'UPDATE hr_tipologie_evento
                 SET descrizione = :descrizione,
                     descrizione_calendario = :descrizione_calendario,
                     richiede_approvazione = :richiede_approvazione,
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
                'descrizione' => $descrizione,
                'descrizione_calendario' => $descrizioneCalendario,
                'richiede_approvazione' => isset($_POST['richiede_approvazione']) ? 1 : 0,
                'consente_giorni' => isset($_POST['consente_giorni']) ? 1 : 0,
                'consente_ore' => isset($_POST['consente_ore']) ? 1 : 0,
                'visibile_calendario' => isset($_POST['visibile_calendario']) ? 1 : 0,
                'visibile_ai_colleghi' => isset($_POST['visibile_ai_colleghi']) ? 1 : 0,
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'ordinamento' => (int)($_POST['ordinamento'] ?? 0),
                'colore_calendario' => $coloreCalendario,
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

            $stmt = $pdo->prepare(
                'UPDATE hr_configurazioni
                 SET valore = :valore,
                     attivo = :attivo
                 WHERE id_configurazione = :id_configurazione'
            );
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
    )->fetchAll(PDO::FETCH_ASSOC);

    $configurazioni = $pdo->query('SELECT * FROM hr_configurazioni ORDER BY codice')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errore = $e->getMessage();
    $tipologie = $tipologie ?? [];
    $configurazioni = $configurazioni ?? [];
}

$palette = hrPaletteColori();

layoutHeader('Configurazione assenze');
?>
<style>
.hr-config-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
    align-items: center;
}
.hr-color-palette {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    min-width: 150px;
    max-width: 190px;
}
.hr-color-option {
    position: relative;
    display: inline-flex;
}
.hr-color-option input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.hr-color-chip {
    width: 26px;
    height: 26px;
    border: 1px solid #d7dee8;
    background: #fff;
    border-radius: 999px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.hr-color-chip:hover {
    transform: translateY(-1px);
    border-color: #94a3b8;
}
.hr-color-dot {
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: var(--dot-color, #6c757d);
    box-shadow: inset 0 0 0 1px rgba(15,23,42,.16);
    flex: 0 0 auto;
}
.hr-color-check {
    position: absolute;
    right: -5px;
    bottom: -5px;
    display: none;
    width: 16px;
    height: 16px;
    border-radius: 999px;
    background: #16a34a;
    color: #fff;
    font-weight: 900;
    font-size: 10px;
    align-items: center;
    justify-content: center;
    box-shadow: 0 0 0 2px #fff;
}
.hr-color-option input:checked + .hr-color-chip {
    border-color: #111827;
    box-shadow: 0 0 0 2px rgba(17, 24, 39, .14);
}
.hr-color-option input:checked + .hr-color-chip .hr-color-check {
    display: inline-flex;
}
.hr-flag-list {
    display: grid;
    gap: 6px;
}
.hr-flag-list label {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 0;
    font-size: 14px;
}
.hr-config-table input[type="text"],
.hr-config-table input[type="number"] {
    width: 100%;
    max-width: 260px;
}
.hr-config-table .col-actions {
    width: 110px;
    text-align: center;
}
.hr-muted-note {
    color: #64748b;
    font-size: 13px;
    margin-top: 6px;
}
@media (max-width: 1000px) {
    .hr-config-table,
    .hr-config-table tbody,
    .hr-config-table tr,
    .hr-config-table td,
    .hr-config-table th {
        display: block;
        width: 100%;
    }
    .hr-config-table thead {
        display: none;
    }
    .hr-config-table tr {
        border: 1px solid #d7dee8;
        border-radius: 12px;
        margin-bottom: 12px;
        padding: 12px;
    }
    .hr-config-table td {
        border: 0 !important;
        padding: 8px 0 !important;
    }
}
</style>

<section class="card page-hero">
    <div>
        <h1>Configurazione assenze</h1>
        <p>Da qui governi il modulo HR: tipologie, relazioni organizzative, gruppi di lavoro e impostazioni principali.</p>
    </div>
    <div class="hr-config-actions">
        <a class="btn" href="relazioni_organizzative.php">Relazioni organizzative</a>
        <a class="btn" href="gruppi_lavoro.php">Gruppi di lavoro</a>
        <a class="btn" href="assenze.php">Vai ad assenze</a>
    </div>
</section>

<?php if ($messaggio !== ''): ?>
    <div class="alert alert-success"><?= h($messaggio) ?></div>
<?php endif; ?>

<?php if ($errore !== ''): ?>
    <div class="alert alert-error"><?= h($errore) ?></div>
<?php endif; ?>

<section class="grid cards-4">
    <div class="card metric-card">
        <h3>Tipologie attive</h3>
        <div class="metric-value"><?= (int)$riepilogo['tipologie_attive'] ?></div>
    </div>
    <div class="card metric-card">
        <h3>Relazioni attive</h3>
        <div class="metric-value"><?= (int)$riepilogo['relazioni_attive'] ?></div>
    </div>
    <div class="card metric-card">
        <h3>Gruppi attivi</h3>
        <div class="metric-value"><?= (int)$riepilogo['gruppi_attivi'] ?></div>
    </div>
    <div class="card metric-card">
        <h3>Appartenenze attive</h3>
        <div class="metric-value"><?= (int)$riepilogo['membri_gruppi_attivi'] ?></div>
    </div>
</section>

<section class="card">
    <h2>Tipologie evento</h2>
    <p class="muted">Il pallino selezionato viene usato nel calendario e nel popup di dettaglio.</p>

    <div class="table-responsive">
        <table class="table hr-config-table">
            <thead>
            <tr>
                <th>Codice</th>
                <th>Descrizione</th>
                <th>Nome calendario</th>
                <th>Presenza</th>
                <th>Regole</th>
                <th>Visibilità</th>
                <th>Ordine</th>
                <th>Colore</th>
                <th class="col-actions">Salva</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tipologie as $tipologia): ?>
                <?php
                $idTipologia = (int)$tipologia['id_tipologia_evento'];
                $selectedColor = hrColoreValido((string)($tipologia['colore_calendario'] ?? ''));
                ?>
                <tr>
                    <form method="post">
                        <td>
                            <strong><?= h((string)$tipologia['codice']) ?></strong>
                            <input type="hidden" name="azione" value="salva_tipologia">
                            <input type="hidden" name="id_tipologia_evento" value="<?= $idTipologia ?>">
                        </td>
                        <td>
                            <input type="text" name="descrizione" value="<?= h((string)$tipologia['descrizione']) ?>" <?= $puoScrivere ? '' : 'readonly' ?> required>
                        </td>
                        <td>
                            <input type="text" name="descrizione_calendario" value="<?= h((string)($tipologia['descrizione_calendario'] ?? '')) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                            <div class="hr-muted-note">Testo mostrato nel calendario.</div>
                        </td>
                        <td><?= h((string)$tipologia['stato_presenza']) ?></td>
                        <td>
                            <div class="hr-flag-list">
                                <label><input type="checkbox" name="richiede_approvazione" value="1" <?= (int)$tipologia['richiede_approvazione'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> approvazione</label>
                                <label><input type="checkbox" name="consente_giorni" value="1" <?= (int)$tipologia['consente_giorni'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> giorni</label>
                                <label><input type="checkbox" name="consente_ore" value="1" <?= (int)$tipologia['consente_ore'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> ore</label>
                            </div>
                        </td>
                        <td>
                            <div class="hr-flag-list">
                                <label><input type="checkbox" name="visibile_calendario" value="1" <?= (int)$tipologia['visibile_calendario'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> calendario</label>
                                <label><input type="checkbox" name="visibile_ai_colleghi" value="1" <?= (int)$tipologia['visibile_ai_colleghi'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> colleghi</label>
                                <label><input type="checkbox" name="attivo" value="1" <?= (int)$tipologia['attivo'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> attiva</label>
                            </div>
                        </td>
                        <td>
                            <input type="number" name="ordinamento" value="<?= (int)$tipologia['ordinamento'] ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                        </td>
                        <td>
                            <div class="hr-color-palette">
                                <?php foreach ($palette as $hex => $label): ?>
                                    <label class="hr-color-option">
                                        <input type="radio" name="colore_calendario" value="<?= h($hex) ?>" <?= strtolower($selectedColor) === strtolower($hex) ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>>
                                        <span class="hr-color-chip" title="<?= h($label) ?>" aria-label="<?= h($label) ?>">
                                            <span class="hr-color-dot" style="--dot-color: <?= h($hex) ?>"></span>
                                            <span class="hr-color-check">✓</span>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="col-actions">
                            <button type="submit" class="btn btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>Salva</button>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card">
    <h2>Configurazioni tecniche</h2>
    <div class="table-responsive">
        <table class="table hr-config-table">
            <thead>
            <tr>
                <th>Codice</th>
                <th>Descrizione</th>
                <th>Valore</th>
                <th>Attiva</th>
                <th class="col-actions">Salva</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($configurazioni as $configurazione): ?>
                <tr>
                    <form method="post">
                        <td>
                            <strong><?= h((string)$configurazione['codice']) ?></strong>
                            <input type="hidden" name="azione" value="salva_configurazione">
                            <input type="hidden" name="id_configurazione" value="<?= (int)$configurazione['id_configurazione'] ?>">
                        </td>
                        <td><?= h((string)$configurazione['descrizione']) ?></td>
                        <td>
                            <input type="text" name="valore" value="<?= h((string)$configurazione['valore']) ?>" <?= $puoScrivere ? '' : 'readonly' ?>>
                        </td>
                        <td>
                            <label><input type="checkbox" name="attivo" value="1" <?= (int)$configurazione['attivo'] === 1 ? 'checked' : '' ?> <?= $puoScrivere ? '' : 'disabled' ?>> sì</label>
                        </td>
                        <td class="col-actions">
                            <button type="submit" class="btn btn-primary" <?= $puoScrivere ? '' : 'disabled' ?>>Salva</button>
                        </td>
                    </form>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php layoutFooter(); ?>
