<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

richiediPermessoLettura('benefici_hr');

$pdo = db();
$puoScrivere = haPermessoScrittura('benefici_hr');
$idOperatore = (int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
$errore = '';
$messaggio = '';

$beneficiDisponibili = [
    'LEGGE_104' => 'Permesso Legge 104',
    'ALLATTAMENTO' => 'Allattamento',
    'CONGEDO_STRAORDINARIO_DISABILI' => 'Congedo straordinario disabili',
    'SMART_WORKING' => 'Smart working',
];

function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrBeneficioLabel(string $codice, array $beneficiDisponibili): string
{
    return $beneficiDisponibili[$codice] ?? $codice;
}

function hrBeneficioValidaUtente(PDO $pdo, int $idUtente): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM aut_utenti u
         INNER JOIN hr_profili_dipendenti hp
            ON hp.id_utente = u.id_utente
           AND hp.attivo = 1
         WHERE u.id_utente = :id_utente
           AND u.attivo = 1
           AND LOWER(u.username) <> 'admin'"
    );
    $stmt->execute(['id_utente' => $idUtente]);

    if ((int)$stmt->fetchColumn() !== 1) {
        throw new RuntimeException('Dipendente non valido o non attivo.');
    }
}

function hrBeneficioSalva(
    PDO $pdo,
    int $idUtente,
    string $tipoBeneficio,
    string $dataInizio,
    string $dataFine,
    float $giorni,
    float $ore,
    float $oreGiornata,
    string $note,
    int $idOperatore
): void {
    if ($tipoBeneficio === 'LEGGE_104') {
        if ($giorni <= 0 || $ore <= 0 || $oreGiornata <= 0) {
            throw new RuntimeException('Per la Legge 104 indica plafond giorni, plafond ore ed equivalenza giornata maggiori di zero.');
        }
    } else {
        $giorni = 0;
        $ore = 0;
        $oreGiornata = 0;
    }

    $minuti = (int)round($ore * 60);
    $minutiGiornata = (int)round($oreGiornata * 60);

    $stmt = $pdo->prepare(
        "INSERT INTO hr_benefici_utenti
            (id_utente, codice_beneficio, data_inizio, data_fine,
             consente_giorni, consente_ore,
             plafond_giorni_mese, plafond_minuti_mese, minuti_giornata_equivalenza,
             note_hr, attivo, aggiornato_da, data_aggiornamento)
         VALUES
            (:id_utente, :codice_beneficio, :data_inizio, :data_fine,
             1, 1,
             :plafond_giorni_mese, :plafond_minuti_mese, :minuti_giornata_equivalenza,
             :note_hr, 1, :aggiornato_da, NOW())
         ON DUPLICATE KEY UPDATE
            data_inizio = VALUES(data_inizio),
            data_fine = VALUES(data_fine),
            consente_giorni = 1,
            consente_ore = 1,
            plafond_giorni_mese = VALUES(plafond_giorni_mese),
            plafond_minuti_mese = VALUES(plafond_minuti_mese),
            minuti_giornata_equivalenza = VALUES(minuti_giornata_equivalenza),
            note_hr = VALUES(note_hr),
            attivo = 1,
            aggiornato_da = VALUES(aggiornato_da),
            data_aggiornamento = NOW()"
    );

    $stmt->execute([
        'id_utente' => $idUtente,
        'codice_beneficio' => $tipoBeneficio,
        'data_inizio' => $dataInizio,
        'data_fine' => $dataFine !== '' ? $dataFine : null,
        'plafond_giorni_mese' => $giorni,
        'plafond_minuti_mese' => $minuti,
        'minuti_giornata_equivalenza' => $minutiGiornata,
        'note_hr' => $note !== '' ? $note : null,
        'aggiornato_da' => $idOperatore > 0 ? $idOperatore : null,
    ]);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        $azione = trim((string)($_POST['azione'] ?? ''));
        $idUtente = (int)($_POST['id_utente'] ?? 0);
        $tipoBeneficio = strtoupper(trim((string)($_POST['tipo_beneficio'] ?? '')));

        if (!isset($beneficiDisponibili[$tipoBeneficio])) {
            throw new RuntimeException('Beneficio o diritto non valido.');
        }
        if ($idUtente <= 0) {
            throw new RuntimeException('Seleziona un dipendente.');
        }

        hrBeneficioValidaUtente($pdo, $idUtente);

        if ($azione === 'revoca_beneficio') {
            $stmt = $pdo->prepare(
                "UPDATE hr_benefici_utenti
                 SET attivo = 0,
                     data_fine = COALESCE(data_fine, CURDATE()),
                     aggiornato_da = :aggiornato_da,
                     data_aggiornamento = NOW()
                 WHERE id_utente = :id_utente
                   AND codice_beneficio = :codice_beneficio"
            );
            $stmt->execute([
                'aggiornato_da' => $idOperatore > 0 ? $idOperatore : null,
                'id_utente' => $idUtente,
                'codice_beneficio' => $tipoBeneficio,
            ]);

            $messaggio = 'Beneficio/diritto revocato correttamente.';
        } elseif (in_array($azione, ['assegna_beneficio', 'salva_beneficio'], true)) {
            $dataInizio = trim((string)($_POST['data_inizio'] ?? ''));
            $dataFine = trim((string)($_POST['data_fine'] ?? ''));
            $giorni = (float)str_replace(',', '.', (string)($_POST['plafond_giorni_mese'] ?? '0'));
            $ore = (float)str_replace(',', '.', (string)($_POST['plafond_ore_mese'] ?? '0'));
            $oreGiornata = (float)str_replace(',', '.', (string)($_POST['ore_giornata_equivalenza'] ?? '0'));
            $note = trim((string)($_POST['note_hr'] ?? ''));

            if ($dataInizio === '') {
                throw new RuntimeException('La data di decorrenza è obbligatoria.');
            }
            if ($dataFine !== '' && $dataFine < $dataInizio) {
                throw new RuntimeException('La data finale non può precedere la data iniziale.');
            }

            hrBeneficioSalva(
                $pdo,
                $idUtente,
                $tipoBeneficio,
                $dataInizio,
                $dataFine,
                $giorni,
                $ore,
                $oreGiornata,
                $note,
                $idOperatore
            );

            $messaggio = $azione === 'assegna_beneficio'
                ? 'Beneficio/diritto assegnato correttamente.'
                : 'Beneficio/diritto aggiornato correttamente.';
        } else {
            throw new RuntimeException('Azione non valida.');
        }
    }

    $utentiDisponibili = $pdo->query(
        "SELECT
            u.id_utente,
            u.username,
            TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS nominativo
         FROM aut_utenti u
         INNER JOIN hr_profili_dipendenti hp
            ON hp.id_utente = u.id_utente
           AND hp.attivo = 1
         WHERE u.attivo = 1
           AND LOWER(u.username) <> 'admin'
         ORDER BY u.cognome, u.nome, u.username"
    )->fetchAll(PDO::FETCH_ASSOC);

    $beneficiAssegnati = $pdo->query(
        "SELECT
            b.id_beneficio_utente,
            b.id_utente,
            b.codice_beneficio,
            b.data_inizio,
            b.data_fine,
            b.plafond_giorni_mese,
            b.plafond_minuti_mese,
            b.minuti_giornata_equivalenza,
            b.note_hr,
            b.data_aggiornamento,
            u.username,
            TRIM(CONCAT(COALESCE(u.nome, ''), ' ', COALESCE(u.cognome, ''))) AS nominativo
         FROM hr_benefici_utenti b
         INNER JOIN aut_utenti u
            ON u.id_utente = b.id_utente
         INNER JOIN hr_profili_dipendenti hp
            ON hp.id_utente = u.id_utente
           AND hp.attivo = 1
         WHERE b.attivo = 1
           AND u.attivo = 1
           AND LOWER(u.username) <> 'admin'
         ORDER BY u.cognome, u.nome, b.codice_beneficio"
    )->fetchAll(PDO::FETCH_ASSOC);

    $riepilogo = [
        'totale' => count($beneficiAssegnati),
        'LEGGE_104' => 0,
        'ALLATTAMENTO' => 0,
        'CONGEDO_STRAORDINARIO_DISABILI' => 0,
        'SMART_WORKING' => 0,
    ];
    foreach ($beneficiAssegnati as $riga) {
        $codice = (string)$riga['codice_beneficio'];
        if (isset($riepilogo[$codice])) {
            $riepilogo[$codice]++;
        }
    }
} catch (Throwable $e) {
    $errore = $e->getMessage();
    $utentiDisponibili = $utentiDisponibili ?? [];
    $beneficiAssegnati = $beneficiAssegnati ?? [];
    $riepilogo = $riepilogo ?? [
        'totale' => 0,
        'LEGGE_104' => 0,
        'ALLATTAMENTO' => 0,
        'CONGEDO_STRAORDINARIO_DISABILI' => 0,
        'SMART_WORKING' => 0,
    ];
}

layoutHeader('Benefici e diritti HR');
?>
<style>
.hr-benefit-stack{display:grid;gap:20px}
.hr-benefit-form-grid{display:grid;grid-template-columns:minmax(220px,1.4fr) minmax(220px,1.1fr) minmax(160px,.8fr) minmax(160px,.8fr);gap:14px;align-items:end}
.hr-benefit-form-grid .form-group{margin:0}
.hr-benefit-form-grid input,.hr-benefit-form-grid select,.hr-benefit-table input,.hr-benefit-table textarea{width:100%;box-sizing:border-box}
.hr-benefit-104-fields{display:grid;grid-template-columns:repeat(3,minmax(130px,1fr));gap:14px;margin-top:14px}
.hr-benefit-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.hr-benefit-summary{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:12px 16px}
.hr-benefit-summary span{white-space:nowrap}
.hr-benefit-table td{vertical-align:middle}
.hr-benefit-table .benefit-note{min-width:190px}
.hr-benefit-table .benefit-date{min-width:145px}
.hr-benefit-table .benefit-params{min-width:285px}
.hr-benefit-param-row{display:flex;align-items:flex-end;gap:8px;flex-wrap:nowrap}
.hr-benefit-param-item{display:flex;flex-direction:column;gap:3px;min-width:0}
.hr-benefit-param-item strong{font-size:.78rem;line-height:1.1;white-space:nowrap}
.hr-benefit-param-item input[type="number"]{width:78px!important;min-width:78px}
.hr-benefit-table .benefit-actions{min-width:160px}
.hr-benefit-empty{padding:18px;color:#667085}
@media(max-width:1050px){.hr-benefit-form-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.hr-benefit-form-grid,.hr-benefit-104-fields{grid-template-columns:1fr}}
</style>

<div class="page-container hr-benefit-stack">
    <div class="card card-wide">
        <div class="section-head">
            <div>
                <h1>Benefici e diritti HR</h1>
                <div class="meta">Assegna i diritti individuali e visualizza solo i dipendenti che hanno un beneficio attivo.</div>
            </div>
            <a class="btn btn-light" href="configurazione_assenze.php">Configurazione assenze</a>
        </div>
    </div>

    <?php if ($messaggio !== ''): ?>
        <div class="alert alert-success"><?= h($messaggio) ?></div>
    <?php endif; ?>
    <?php if ($errore !== ''): ?>
        <div class="alert alert-error"><?= h($errore) ?></div>
    <?php endif; ?>

    <div class="card card-wide">
        <div class="section-head">
            <div>
                <h2>Assegna beneficio/diritto</h2>
                <div class="meta">Seleziona dipendente e diritto. Se l'assegnazione esiste già, viene riattivata e aggiornata.</div>
            </div>
        </div>

        <?php if (!$puoScrivere): ?>
            <div class="info-box">Il tuo profilo può consultare i benefici ma non modificarli.</div>
        <?php else: ?>
            <form method="post" id="form-nuovo-beneficio">
                <input type="hidden" name="azione" value="assegna_beneficio">

                <div class="hr-benefit-form-grid">
                    <div class="form-group">
                        <label for="id_utente"><strong>Dipendente</strong></label>
                        <select name="id_utente" id="id_utente" required>
                            <option value="">Seleziona...</option>
                            <?php foreach ($utentiDisponibili as $utente): ?>
                                <option value="<?= (int)$utente['id_utente'] ?>">
                                    <?= h(trim((string)$utente['nominativo']) !== '' ? (string)$utente['nominativo'] : (string)$utente['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="tipo_beneficio"><strong>Beneficio / diritto</strong></label>
                        <select name="tipo_beneficio" id="tipo_beneficio" required>
                            <?php foreach ($beneficiDisponibili as $codice => $label): ?>
                                <option value="<?= h($codice) ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="data_inizio"><strong>Decorrenza da</strong></label>
                        <input type="date" name="data_inizio" id="data_inizio" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="data_fine"><strong>Decorrenza a</strong></label>
                        <input type="date" name="data_fine" id="data_fine">
                    </div>
                </div>

                <div class="hr-benefit-104-fields" id="campi-legge-104">
                    <div class="form-group">
                        <label for="plafond_giorni_mese"><strong>Plafond giorni/mese</strong></label>
                        <input type="number" min="0.01" step="0.01" name="plafond_giorni_mese" id="plafond_giorni_mese" value="3">
                    </div>
                    <div class="form-group">
                        <label for="plafond_ore_mese"><strong>Plafond ore/mese</strong></label>
                        <input type="number" min="0.01" step="0.01" name="plafond_ore_mese" id="plafond_ore_mese" value="24.00">
                    </div>
                    <div class="form-group">
                        <label for="ore_giornata_equivalenza"><strong>Ore per giornata</strong></label>
                        <input type="number" min="0.01" step="0.01" name="ore_giornata_equivalenza" id="ore_giornata_equivalenza" value="8.00">
                    </div>
                </div>

                <div class="form-group" style="margin-top:14px">
                    <label for="note_hr"><strong>Note HR</strong></label>
                    <textarea name="note_hr" id="note_hr" rows="2" placeholder="Note facoltative"></textarea>
                </div>

                <div class="hr-benefit-actions" style="margin-top:14px">
                    <button type="submit" class="btn btn-primary"><i class="la la-plus-circle" aria-hidden="true"></i> Assegna</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div class="card card-compact hr-benefit-summary">
        <span><strong><?= (int)$riepilogo['totale'] ?></strong> assegnazioni attive</span>
        <span><strong><?= (int)$riepilogo['LEGGE_104'] ?></strong> Legge 104</span>
        <span><strong><?= (int)$riepilogo['ALLATTAMENTO'] ?></strong> allattamento</span>
        <span><strong><?= (int)$riepilogo['CONGEDO_STRAORDINARIO_DISABILI'] ?></strong> congedo straordinario</span>
        <span><strong><?= (int)$riepilogo['SMART_WORKING'] ?></strong> smart working</span>
    </div>

    <div class="card card-wide">
        <div class="section-head">
            <div>
                <h2>Benefici e diritti assegnati</h2>
                <div class="meta">Sono mostrati solo i benefici attivi. Revoca disattiva il diritto senza cancellarne la registrazione.</div>
            </div>
            <div class="quick-filter">
                <label for="filtro-benefici-attivi">Filtro rapido</label>
                <input type="search" id="filtro-benefici-attivi" class="quick-filter-input" placeholder="Cerca dipendente o beneficio..." data-quick-filter="tabella-benefici-attivi">
            </div>
        </div>

        <?php if (!$beneficiAssegnati): ?>
            <div class="hr-benefit-empty">Nessun beneficio o diritto attivo assegnato.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="hr-benefit-table" id="tabella-benefici-attivi">
                    <thead>
                    <tr>
                        <th>Dipendente</th>
                        <th>Beneficio / diritto</th>
                        <th>Dal</th>
                        <th>Al</th>
                        <th>Parametri</th>
                        <th>Note HR</th>
                        <th>Azioni</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($beneficiAssegnati as $beneficio): ?>
                        <?php
                        $formId = 'beneficio-' . (int)$beneficio['id_beneficio_utente'];
                        $is104 = (string)$beneficio['codice_beneficio'] === 'LEGGE_104';
                        $oreMese = ((int)$beneficio['plafond_minuti_mese']) / 60;
                        $oreGiornata = ((int)$beneficio['minuti_giornata_equivalenza']) / 60;
                        ?>
                        <tr>
                            <td>
                                <strong><?= h(trim((string)$beneficio['nominativo']) !== '' ? (string)$beneficio['nominativo'] : (string)$beneficio['username']) ?></strong>
                                <form method="post" id="<?= h($formId) ?>">
                                    <input type="hidden" name="id_utente" value="<?= (int)$beneficio['id_utente'] ?>">
                                    <input type="hidden" name="tipo_beneficio" value="<?= h((string)$beneficio['codice_beneficio']) ?>">
                                </form>
                            </td>
                            <td><?= h(hrBeneficioLabel((string)$beneficio['codice_beneficio'], $beneficiDisponibili)) ?></td>
                            <td class="benefit-date">
                                <input form="<?= h($formId) ?>" type="date" name="data_inizio" value="<?= h((string)$beneficio['data_inizio']) ?>" required>
                            </td>
                            <td class="benefit-date">
                                <input form="<?= h($formId) ?>" type="date" name="data_fine" value="<?= h((string)($beneficio['data_fine'] ?? '')) ?>">
                            </td>
                            <td class="benefit-params">
                                <?php if ($is104): ?>
                                    <div class="hr-benefit-param-row">
                                        <label class="hr-benefit-param-item">
                                            <strong>Giorni</strong>
                                            <input form="<?= h($formId) ?>" type="number" min="0.01" step="0.01" name="plafond_giorni_mese" value="<?= h((string)$beneficio['plafond_giorni_mese']) ?>">
                                        </label>
                                        <label class="hr-benefit-param-item">
                                            <strong>Ore</strong>
                                            <input form="<?= h($formId) ?>" type="number" min="0.01" step="0.01" name="plafond_ore_mese" value="<?= h(number_format($oreMese, 2, '.', '')) ?>">
                                        </label>
                                        <label class="hr-benefit-param-item">
                                            <strong>Ore/giorno</strong>
                                            <input form="<?= h($formId) ?>" type="number" min="0.01" step="0.01" name="ore_giornata_equivalenza" value="<?= h(number_format($oreGiornata, 2, '.', '')) ?>">
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <span class="meta">Nessun plafond</span>
                                    <input form="<?= h($formId) ?>" type="hidden" name="plafond_giorni_mese" value="0">
                                    <input form="<?= h($formId) ?>" type="hidden" name="plafond_ore_mese" value="0">
                                    <input form="<?= h($formId) ?>" type="hidden" name="ore_giornata_equivalenza" value="0">
                                <?php endif; ?>
                            </td>
                            <td class="benefit-note">
                                <textarea form="<?= h($formId) ?>" name="note_hr" rows="2"><?= h((string)($beneficio['note_hr'] ?? '')) ?></textarea>
                            </td>
                            <td class="benefit-actions">
                                <?php if ($puoScrivere): ?>
                                    <div class="hr-benefit-actions">
                                        <button form="<?= h($formId) ?>" type="submit" name="azione" value="salva_beneficio" class="btn btn-light"><i class="la la-save" aria-hidden="true"></i> Salva</button>
                                        <button form="<?= h($formId) ?>" type="submit" name="azione" value="revoca_beneficio" class="btn btn-danger" onclick="return confirm('Revocare questo beneficio/diritto?');"><i class="la la-ban" aria-hidden="true"></i> Revoca</button>
                                    </div>
                                <?php else: ?>
                                    <span class="meta">Sola lettura</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="hr-benefit-empty" data-quick-filter-empty="tabella-benefici-attivi" style="display:none">Nessun beneficio corrisponde al filtro.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const tipo = document.getElementById('tipo_beneficio');
    const campi104 = document.getElementById('campi-legge-104');
    if (!tipo || !campi104) return;

    function aggiornaCampi104() {
        campi104.style.display = tipo.value === 'LEGGE_104' ? 'grid' : 'none';
    }

    tipo.addEventListener('change', aggiornaCampi104);
    aggiornaCampi104();
})();
</script>

<?php layoutFooter(); ?>
