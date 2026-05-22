<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui.php';

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
    'recapiti_email_attivi' => 0,
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
    $riepilogo['recapiti_email_attivi'] = (int)$pdo->query("SELECT COUNT(*) FROM hr_recapiti_utenti ru INNER JOIN hr_tipi_recapito tr ON tr.id_tipo_recapito = ru.id_tipo_recapito WHERE ru.attivo = 1 AND tr.codice IN ('EMAIL_LAVORO','EMAIL_PERSONALE')")->fetchColumn();

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
<link rel="stylesheet" href="/assets/hr.css">

<div class="hr-config-stack">
    <section class="card card-compact hr-config-hero">
        <div class="section-head">
            <div>
                <h1>Configurazione assenze</h1>
                <div class="meta">Tipologie, colori, regole principali e impostazioni del modulo HR.</div>
            </div>
            <div class="section-head-actions hr-config-actions">
                <a class="btn" href="relazioni_organizzative.php"><i class="la la-sitemap" aria-hidden="true"></i> Relazioni</a>
                <a class="btn" href="gruppi_lavoro.php"><i class="la la-users-cog" aria-hidden="true"></i> Team</a>
                <a class="btn" href="recapiti_utenti.php"><i class="la la-envelope" aria-hidden="true"></i> Recapiti</a>
                <a class="btn btn-light" href="assenze.php"><i class="la la-calendar" aria-hidden="true"></i> Vai ad assenze</a>
            </div>
        </div>
    </section>

    <section class="hr-config-summary">
        <span><strong><?= (int)$riepilogo['tipologie_attive'] ?></strong> tipologie attive</span>
        <span><strong><?= (int)$riepilogo['relazioni_attive'] ?></strong> relazioni attive</span>
        <span><strong><?= (int)$riepilogo['gruppi_attivi'] ?></strong> team attivi</span>
        <span><strong><?= (int)$riepilogo['membri_gruppi_attivi'] ?></strong> appartenenze attive</span>
        <span><strong><?= (int)$riepilogo['recapiti_email_attivi'] ?></strong> email attive</span>
    </section>

    <?php if ($messaggio !== ''): ?>
        <div class="alert alert-success"><?= h($messaggio) ?></div>
    <?php endif; ?>

    <?php if ($errore !== ''): ?>
        <div class="alert alert-error"><?= h($errore) ?></div>
    <?php endif; ?>

    <section class="card card-wide hr-config-card">
        <div class="hr-config-section-head">
            <div>
                <h2>Tipologie evento</h2>
                <div class="meta">Definisci cosa viene mostrato agli utenti e come appare nel calendario.</div>
            </div>
        </div>

        <div class="table-wrap hr-config-table-wrap">
            <table class="hr-config-table">
                <thead>
                <tr>
                    <th>Tipologia</th>
                    <th>Nome calendario</th>
                    <th>Presenza</th>
                    <th>Regole</th>
                    <th>Visibilità</th>
                    <th>Ordine</th>
                    <th>Colore</th>
                    <th class="col-actions">Azioni</th>
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
                                <strong><?= h((string)$tipologia['descrizione']) ?></strong>
                                <div class="hr-muted-note">Codice: <?= h((string)$tipologia['codice']) ?></div>
                                <input type="hidden" name="azione" value="salva_tipologia">
                                <input type="hidden" name="id_tipologia_evento" value="<?= $idTipologia ?>">
                                <input type="hidden" name="descrizione" value="<?= h((string)$tipologia['descrizione']) ?>">
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

    <section class="card card-wide hr-config-card">
        <div class="hr-config-section-head">
            <div>
                <h2>Configurazioni tecniche</h2>
                <div class="meta">Parametri avanzati del modulo: da modificare solo se necessario.</div>
            </div>
        </div>
        <div class="table-wrap hr-config-table-wrap">
            <table class="hr-config-table">
                <thead>
                <tr>
                    <th>Parametro</th>
                    <th>Descrizione</th>
                    <th>Valore</th>
                    <th>Attiva</th>
                    <th class="col-actions">Azioni</th>
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
</div>

<?php layoutFooter(); ?>
