<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/badge.php';
require_once __DIR__ . '/includes/hr_organizzazione.php';

richiediPermessoLettura('profili_dipendenti');

$pdo = db();
$puoScrivere = haPermessoScrittura('profili_dipendenti');
$csrfToken = hrOrgCsrfToken();
$idProfiloSelezionato = (int)($_GET['profilo'] ?? $_POST['id_profilo_dipendente'] ?? 0);
$errore = '';
$messaggio = '';
$profili = [];
$reparti = [];
$centriCosto = [];
$utentiResponsabili = [];
$responsabiliByUtente = [];
$responsabilePrincipaleByUtente = [];
$teamByUtente = [];
function h(?string $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function hrProfiloData(?string $valore): ?string
{
    $valore = trim((string)$valore);
    if ($valore === '') {
        return null;
    }

    return hrOrgData($valore);
}

function hrProfiloLabelUtente(array $profilo): string
{
    $nome = trim((string)($profilo['nome'] ?? ''));
    $cognome = trim((string)($profilo['cognome'] ?? ''));
    $username = trim((string)($profilo['username'] ?? ''));
    $nominativo = trim($nome . ' ' . $cognome);

    if ($nominativo !== '') {
        return $nominativo;
    }

    return $username;
}

function hrProfiloDescrizioneUtente(array $profilo): string
{
    $username = trim((string)($profilo['username'] ?? ''));
    if ($username === '') {
        return '';
    }

    return '@' . $username;
}

function hrProfiloValore(?string $valore, string $fallback = 'Non assegnato'): string
{
    $valore = trim((string)$valore);
    return $valore !== '' ? $valore : $fallback;
}

function hrProfiloBadgeHtml(string $testo, string $classe = ''): string
{
    $classAttr = trim('hr-profile-pill ' . $classe);
    return '<span class="' . h($classAttr) . '">' . h($testo) . '</span>';
}

function hrProfiloNominativoOpzione(array $utente): string
{
    $nominativo = trim((string)($utente['nominativo'] ?? ''));
    $username = trim((string)($utente['username'] ?? ''));

    if ($nominativo !== '') {
        return $nominativo . ($username !== '' ? ' (' . $username . ')' : '');
    }

    return $username;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) {
            throw new RuntimeException('Non hai i permessi di modifica.');
        }

        hrOrgVerificaCsrf();
        $azione = trim((string)($_POST['azione'] ?? ''));
        if ($azione === 'crea_profili_mancanti') {
            $pdo->exec('INSERT INTO hr_profili_dipendenti (id_utente, attivo) SELECT u.id_utente, 1 FROM aut_utenti u WHERE u.attivo = 1 AND NOT EXISTS (SELECT 1 FROM hr_profili_dipendenti p WHERE p.id_utente = u.id_utente)');
            header('Location: profili_dipendenti.php?creati=1');
            exit;
        }

        if ($azione === 'salva_profilo') {
            $idProfilo = (int)($_POST['id_profilo_dipendente'] ?? 0);
            if ($idProfilo <= 0) {
                throw new RuntimeException('Profilo dipendente non valido.');
            }

            $stmtProfilo = $pdo->prepare('SELECT p.id_utente FROM hr_profili_dipendenti p INNER JOIN aut_utenti u ON u.id_utente = p.id_utente AND u.attivo = 1 WHERE p.id_profilo_dipendente = :id');
            $stmtProfilo->execute(['id' => $idProfilo]);
            $profiloCorrente = $stmtProfilo->fetch(PDO::FETCH_ASSOC);
            if (!$profiloCorrente) {
                throw new RuntimeException('Profilo dipendente non trovato.');
            }

            $idUtenteProfilo = (int)$profiloCorrente['id_utente'];
            $idReparto = (int)($_POST['id_reparto'] ?? 0);
            $idCentroCosto = (int)($_POST['id_centro_costo'] ?? 0);
            $idResponsabile = (int)($_POST['id_responsabile'] ?? 0);
            $matricola = trim((string)($_POST['matricola'] ?? ''));
            $mansione = trim((string)($_POST['mansione'] ?? ''));
            $dataAssunzione = hrProfiloData((string)($_POST['data_assunzione'] ?? ''));
            $dataCessazione = hrProfiloData((string)($_POST['data_cessazione'] ?? ''));
            $noteHr = trim((string)($_POST['note_hr'] ?? ''));

            if ($dataAssunzione !== null && $dataCessazione !== null && $dataCessazione < $dataAssunzione) {
                throw new RuntimeException('La data di cessazione non può precedere la data di assunzione.');
            }
            if ($idResponsabile > 0 && $idResponsabile === $idUtenteProfilo) {
                throw new RuntimeException('Il responsabile non può coincidere con il dipendente.');
            }

            $idTipoResponsabile = 0;
            $stmtTipo = $pdo->prepare(
                "SELECT id_tipo_relazione
                 FROM hr_tipi_relazione_organizzativa
                 WHERE attivo = 1 AND codice IN ('RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO')
                 ORDER BY CASE WHEN codice = 'RESPONSABILE_FUNZIONALE' THEN 0 ELSE 1 END
                 LIMIT 1"
            );
            $stmtTipo->execute();
            $idTipoResponsabile = (int)$stmtTipo->fetchColumn();
            if ($idResponsabile > 0 && $idTipoResponsabile <= 0) {
                throw new RuntimeException('Tipo relazione responsabile non configurato.');
            }

            if ($idResponsabile > 0) {
                $stmtUtente = $pdo->prepare('SELECT COUNT(*) FROM aut_utenti WHERE id_utente = :id_utente AND attivo = 1');
                $stmtUtente->execute(['id_utente' => $idResponsabile]);
                if ((int)$stmtUtente->fetchColumn() === 0) {
                    throw new RuntimeException('Responsabile selezionato non valido o non attivo.');
                }
            }

            foreach (['hr_reparti' => ['id_reparto', $idReparto], 'hr_centri_costo' => ['id_centro_costo', $idCentroCosto]] as $tabella => $riferimento) {
                if ($riferimento[1] > 0) {
                    $controllo = $pdo->prepare("SELECT COUNT(*) FROM {$tabella} WHERE {$riferimento[0]} = :id AND attivo = 1");
                    $controllo->execute(['id' => $riferimento[1]]);
                    if ((int)$controllo->fetchColumn() !== 1) throw new RuntimeException('Reparto o centro di costo non valido o non attivo.');
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'UPDATE hr_profili_dipendenti
                 SET matricola = :matricola,
                     mansione = :mansione,
                     id_reparto = :id_reparto,
                     id_centro_costo = :id_centro_costo,
                     data_assunzione = :data_assunzione,
                     data_cessazione = :data_cessazione,
                     note_hr = :note_hr,
                     attivo = :attivo,
                     data_aggiornamento = NOW()
                 WHERE id_profilo_dipendente = :id_profilo_dipendente'
            );
            $stmt->execute([
                'matricola' => $matricola !== '' ? $matricola : null,
                'mansione' => $mansione !== '' ? $mansione : null,
                'id_reparto' => $idReparto > 0 ? $idReparto : null,
                'id_centro_costo' => $idCentroCosto > 0 ? $idCentroCosto : null,
                'data_assunzione' => $dataAssunzione,
                'data_cessazione' => $dataCessazione,
                'note_hr' => $noteHr !== '' ? $noteHr : null,
                'attivo' => isset($_POST['attivo']) ? 1 : 0,
                'id_profilo_dipendente' => $idProfilo,
            ]);

            $oggi = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
            hrOrgAssegnaResponsabile($pdo, $idUtenteProfilo, $idResponsabile, $idTipoResponsabile, $oggi, null, 'Assegnata da anagrafica HR', true);

            $pdo->commit();

            header('Location: profili_dipendenti.php?' . http_build_query(['ok'=>1,'profilo'=>$idProfilo,'q'=>trim((string)($_GET['q'] ?? '')),'reparto'=>(int)($_GET['reparto'] ?? 0),'centro'=>(int)($_GET['centro'] ?? 0),'stato'=>(string)($_GET['stato'] ?? '')]));
            exit;
        }
    }

    if (isset($_GET['ok'])) {
        $messaggio = 'Profilo dipendente aggiornato correttamente.';
    }

    if (isset($_GET['creati'])) $messaggio = 'Profili mancanti creati.';
    $profiliMancanti = (int)$pdo->query('SELECT COUNT(*) FROM aut_utenti u WHERE u.attivo = 1 AND NOT EXISTS (SELECT 1 FROM hr_profili_dipendenti p WHERE p.id_utente = u.id_utente)')->fetchColumn();

    $reparti = $pdo->query('SELECT id_reparto, codice, nome FROM hr_reparti WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);
    $centriCosto = $pdo->query('SELECT id_centro_costo, codice, nome FROM hr_centri_costo WHERE attivo = 1 ORDER BY ordinamento, nome')->fetchAll(PDO::FETCH_ASSOC);
    $utentiResponsabili = $pdo->query(
        "SELECT id_utente,
                username,
                TRIM(CONCAT(COALESCE(nome,''), ' ', COALESCE(cognome,''))) AS nominativo
         FROM aut_utenti
         WHERE attivo = 1
         ORDER BY cognome, nome, username"
    )->fetchAll(PDO::FETCH_ASSOC);

    $profili = $pdo->query(
        'SELECT v.*, u.attivo AS account_attivo, p.attivo AS profilo_hr_attivo
         FROM v_hr_profili_dipendenti v
         INNER JOIN aut_utenti u ON u.id_utente = v.id_utente
         INNER JOIN hr_profili_dipendenti p ON p.id_profilo_dipendente = v.id_profilo_dipendente
         ORDER BY v.cognome, v.nome, v.utente_test, v.username'
    )->fetchAll(PDO::FETCH_ASSOC);

    $responsabiliRows = $pdo->query(
        "SELECT ro.id_utente,
                ro.id_utente_collegato,
                tro.codice AS tipo_codice,
                tro.descrizione AS tipo_descrizione,
                CONCAT(COALESCE(u.nome,''), ' ', COALESCE(u.cognome,'')) AS responsabile_nome,
                u.username AS responsabile_username,
                ro.data_inizio
         FROM hr_relazioni_organizzative ro
         INNER JOIN hr_tipi_relazione_organizzativa tro ON tro.id_tipo_relazione = ro.id_tipo_relazione
         INNER JOIN aut_utenti u ON u.id_utente = ro.id_utente_collegato
         WHERE ro.attiva = 1
           AND ro.data_inizio <= CURDATE()
           AND (ro.data_fine IS NULL OR ro.data_fine >= CURDATE())
         ORDER BY ro.id_utente,
                  CASE WHEN tro.codice = 'RESPONSABILE_FUNZIONALE' THEN 0 WHEN tro.codice = 'RESPONSABILE_DIRETTO' THEN 1 ELSE 2 END,
                  ro.data_inizio DESC,
                  u.cognome,
                  u.nome"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($responsabiliRows as $row) {
        $idUtente = (int)$row['id_utente'];
        $nome = trim((string)($row['responsabile_nome'] ?? ''));
        $username = trim((string)($row['responsabile_username'] ?? ''));
        $label = $nome !== '' ? $nome : $username;
        $tipo = trim((string)($row['tipo_descrizione'] ?? ''));
        $responsabiliByUtente[$idUtente][] = [
            'id_utente_collegato' => (int)$row['id_utente_collegato'],
            'label' => $label,
            'tipo' => $tipo,
            'codice' => trim((string)($row['tipo_codice'] ?? '')),
            'username' => $username,
        ];

        if (!isset($responsabilePrincipaleByUtente[$idUtente]) && in_array((string)$row['tipo_codice'], ['RESPONSABILE_FUNZIONALE', 'RESPONSABILE_DIRETTO'], true)) {
            $responsabilePrincipaleByUtente[$idUtente] = (int)$row['id_utente_collegato'];
        }
    }

    $teamRows = $pdo->query(
        "SELECT gu.id_utente,
                gl.nome AS gruppo_nome,
                gl.codice AS gruppo_codice,
                gu.ruolo_nel_gruppo
         FROM hr_gruppi_utenti gu
         INNER JOIN hr_gruppi_lavoro gl ON gl.id_gruppo_lavoro = gu.id_gruppo_lavoro
         WHERE gu.attivo = 1
           AND gu.data_inizio <= CURDATE()
           AND gl.attivo = 1
           AND (gu.data_fine IS NULL OR gu.data_fine >= CURDATE())
         ORDER BY gu.id_utente, gl.nome"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($teamRows as $row) {
        $idUtente = (int)$row['id_utente'];
        $teamByUtente[$idUtente][] = [
            'nome' => trim((string)($row['gruppo_nome'] ?? '')),
            'codice' => trim((string)($row['gruppo_codice'] ?? '')),
            'ruolo' => trim((string)($row['ruolo_nel_gruppo'] ?? '')),
        ];
    }

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errore = $e->getMessage();
}

function hrProfiloTestoRicerca(string $s): string
{
    return strtolower(strtr($s, ['À'=>'a','È'=>'e','É'=>'e','Ì'=>'i','Ò'=>'o','Ù'=>'u','à'=>'a','è'=>'e','é'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']));
}
$q = trim((string)($_GET['q'] ?? ''));
$filtroReparto = (int)($_GET['reparto'] ?? 0);
$filtroCentro = (int)($_GET['centro'] ?? 0);
$filtroStato = (string)($_GET['stato'] ?? '');
$profiliVisibili = [];
$profiloSelezionato = null;
$profiliAttivi = 0;
foreach ($profili as $profilo) {
    $idUtente = (int)$profilo['id_utente'];
    if ((int)$profilo['id_profilo_dipendente'] === $idProfiloSelezionato) $profiloSelezionato = $profilo;
    $operativo = (int)$profilo['account_attivo'] === 1 && (int)$profilo['profilo_hr_attivo'] === 1;
    if ($operativo) $profiliAttivi++;
    $testo = implode(' ', [hrProfiloLabelUtente($profilo), $profilo['username'], $profilo['matricola'] ?? '', $profilo['mansione'] ?? '', $profilo['reparto'] ?? '', $profilo['centro_costo'] ?? '', $profilo['codice_centro_costo'] ?? '', $profilo['note_hr'] ?? '', implode(' ', array_column($responsabiliByUtente[$idUtente] ?? [], 'label')), implode(' ', array_column($teamByUtente[$idUtente] ?? [], 'nome'))]);
    if ($q !== '' && strpos(hrProfiloTestoRicerca($testo), hrProfiloTestoRicerca($q)) === false) continue;
    if ($filtroReparto > 0 && (int)($profilo['id_reparto'] ?? 0) !== $filtroReparto) continue;
    if ($filtroCentro > 0 && (int)($profilo['id_centro_costo'] ?? 0) !== $filtroCentro) continue;
    if ($filtroStato === 'attivi' && !$operativo) continue;
    if ($filtroStato === 'account_disattivo' && (int)$profilo['account_attivo'] === 1) continue;
    if ($filtroStato === 'hr_disattivo' && (int)$profilo['profilo_hr_attivo'] === 1) continue;
    $profiliVisibili[] = $profilo;
}
$parametriFiltro = ['q'=>$q,'reparto'=>$filtroReparto,'centro'=>$filtroCentro,'stato'=>$filtroStato];
layoutHeader('Profili dipendenti');
?>
<link rel="stylesheet" href="/assets/hr.css">
<style>
.hr-directory-stack{display:grid;gap:20px;min-width:0}.hr-directory-stack>.card{min-width:0}.hr-directory-filters{display:grid;grid-template-columns:minmax(200px,2fr) repeat(3,minmax(130px,1fr));gap:16px}.hr-directory-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:12px}.hr-directory-table th,.hr-directory-table td{vertical-align:middle}.hr-directory-table .meta{font-size:13px}.hr-directory-editor{scroll-margin-top:24px}.hr-directory-table{min-width:920px}@media(max-width:750px){.hr-directory-filters{grid-template-columns:1fr}.hr-directory-stack .section-head{flex-direction:column}.hr-directory-stack .section-head-actions{width:100%}}
</style>
<div class="hr-directory-stack">
<section class="card card-compact"><div class="section-head"><div><h1>Profili dipendenti</h1><div class="meta">Assegnazioni e dati HR delle persone.</div></div><div class="section-head-actions"><a class="btn btn-light" href="reparti.php">Reparti</a><a class="btn btn-light" href="centri_costo.php">Centri di costo</a><a class="btn btn-light" href="relazioni_organizzative.php">Relazioni organizzative</a><a class="btn btn-light" href="export_profili_dipendenti.php">Esporta Excel</a><a class="btn btn-light" href="configurazione_assenze.php">Configurazione</a></div></div></section>
<?php if ($errore !== ''): ?><div class="alert alert-error" role="alert"><?= h($errore) ?></div><?php endif; ?>
<?php if ($messaggio !== ''): ?><div class="alert alert-success" role="status"><?= h($messaggio) ?></div><?php endif; ?>
<?php if (($profiliMancanti ?? 0) > 0): ?><section class="card"><p><?= (int)$profiliMancanti ?> account attivi non hanno ancora un profilo HR.</p><?php if ($puoScrivere): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="azione" value="crea_profili_mancanti"><button class="btn btn-primary" type="submit">Crea profili mancanti</button></form><?php endif; ?></section><?php endif; ?>
<?php if ($profiloSelezionato !== null): ?>
<?php $profilo = $profiloSelezionato; $idProfilo = (int)$profilo['id_profilo_dipendente']; $idUtente = (int)$profilo['id_utente']; $idResponsabilePrincipale = (int)($responsabilePrincipaleByUtente[$idUtente] ?? 0); $puoModificareProfilo = $puoScrivere && (int)$profilo['account_attivo'] === 1; ?>
<section class="card hr-directory-editor" id="profilo"><div class="section-head"><div><h2><?= h(hrProfiloLabelUtente($profilo)) ?></h2><div class="meta"><?= h(hrProfiloDescrizioneUtente($profilo)) ?> · Account <?= (int)$profilo['account_attivo'] === 1 ? 'attivo' : 'disattivo' ?> · Profilo HR <?= (int)$profilo['profilo_hr_attivo'] === 1 ? 'attivo' : 'disattivo' ?></div></div><a class="btn btn-light" href="profili_dipendenti.php?<?= h(http_build_query($parametriFiltro)) ?>">Chiudi scheda</a></div>
<?php $responsabilitaGerarchiche = array_filter($responsabiliByUtente[$idUtente] ?? [], static fn(array $r): bool => in_array($r['codice'], ['RESPONSABILE_DIRETTO','RESPONSABILE_FUNZIONALE'], true)); if (count($responsabilitaGerarchiche) > 1): ?><div class="info-box">Questa persona ha più responsabilità attuali. Verifica il responsabile selezionato: salvando il profilo verrà mantenuto un solo responsabile da oggi, conservando lo storico precedente.</div><?php endif; ?>
<?php if (!$puoModificareProfilo): ?><p class="info-box">Scheda consultabile in sola lettura.</p><?php endif; ?>
                    <form method="post" action="profili_dipendenti.php?<?= h(http_build_query($parametriFiltro)) ?>" class="hr-profile-form">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="azione" value="salva_profilo">
                        <input type="hidden" name="id_profilo_dipendente" value="<?= $idProfilo ?>">

                        <div class="info-box">Il cambio di responsabile vale da oggi e conserva lo storico. Le assegnazioni con altre date si gestiscono in Relazioni organizzative.</div>

                        <div class="hr-profile-form-grid">
                            <div class="form-group">
                                <label for="matricola_<?= $idProfilo ?>">Matricola</label>
                                <input type="text" id="matricola_<?= $idProfilo ?>" name="matricola" value="<?= h((string)($profilo['matricola'] ?? '')) ?>" maxlength="50" <?= $puoModificareProfilo ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="mansione_<?= $idProfilo ?>">Mansione</label>
                                <input type="text" id="mansione_<?= $idProfilo ?>" name="mansione" value="<?= h((string)($profilo['mansione'] ?? '')) ?>" maxlength="150" <?= $puoModificareProfilo ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="reparto_<?= $idProfilo ?>">Reparto</label>
                                <select id="reparto_<?= $idProfilo ?>" name="id_reparto" <?= $puoModificareProfilo ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($reparti as $repartoRow): ?>
                                        <option value="<?= (int)$repartoRow['id_reparto'] ?>" <?= (int)($profilo['id_reparto'] ?? 0) === (int)$repartoRow['id_reparto'] ? 'selected' : '' ?>>
                                            <?= h((string)$repartoRow['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="centro_costo_<?= $idProfilo ?>">Centro di costo</label>
                                <select id="centro_costo_<?= $idProfilo ?>" name="id_centro_costo" <?= $puoModificareProfilo ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($centriCosto as $centroCostoRow): ?>
                                        <option value="<?= (int)$centroCostoRow['id_centro_costo'] ?>" <?= (int)($profilo['id_centro_costo'] ?? 0) === (int)$centroCostoRow['id_centro_costo'] ? 'selected' : '' ?>>
                                            <?= h((string)$centroCostoRow['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group hr-profile-form-wide">
                                <label for="responsabile_<?= $idProfilo ?>">Responsabile funzionale</label>
                                <select id="responsabile_<?= $idProfilo ?>" name="id_responsabile" <?= $puoModificareProfilo ? '' : 'disabled' ?>>
                                    <option value="">Non assegnato</option>
                                    <?php foreach ($utentiResponsabili as $utenteResponsabile): ?>
                                        <?php $idOpzioneResponsabile = (int)$utenteResponsabile['id_utente']; ?>
                                        <?php if ($idOpzioneResponsabile === $idUtente) { continue; } ?>
                                        <option value="<?= $idOpzioneResponsabile ?>" <?= $idResponsabilePrincipale === $idOpzioneResponsabile ? 'selected' : '' ?>>
                                            <?= h(hrProfiloNominativoOpzione($utenteResponsabile)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assunzione_<?= $idProfilo ?>">Data assunzione</label>
                                <input type="date" id="assunzione_<?= $idProfilo ?>" name="data_assunzione" value="<?= h((string)($profilo['data_assunzione'] ?? '')) ?>" <?= $puoModificareProfilo ? '' : 'readonly' ?>>
                            </div>
                            <div class="form-group">
                                <label for="cessazione_<?= $idProfilo ?>">Data cessazione</label>
                                <input type="date" id="cessazione_<?= $idProfilo ?>" name="data_cessazione" value="<?= h((string)($profilo['data_cessazione'] ?? '')) ?>" <?= $puoModificareProfilo ? '' : 'readonly' ?>>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="note_hr_<?= $idProfilo ?>">Note HR</label>
                            <textarea id="note_hr_<?= $idProfilo ?>" name="note_hr" rows="3" <?= $puoModificareProfilo ? '' : 'readonly' ?>><?= h((string)($profilo['note_hr'] ?? '')) ?></textarea>
                        </div>

                        <div class="hr-profile-form-actions">
                            <label class="meta"><input type="checkbox" name="attivo" value="1" <?= (int)$profilo['profilo_hr_attivo'] === 1 ? 'checked' : '' ?> <?= $puoModificareProfilo ? '' : 'disabled' ?>> Profilo HR attivo (non modifica l’accesso al portale)</label>
                            <button type="submit" class="btn btn-primary" <?= $puoModificareProfilo ? '' : 'disabled' ?>>
                                <i class="la la-save" aria-hidden="true"></i> Salva profilo
                            </button>
                        </div>
                    </form>
</section>
<?php endif; ?>
<section class="card"><form method="get" action="profili_dipendenti.php"><div class="hr-directory-filters">
<div class="form-group"><label for="q">Cerca persona</label><input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Nome, matricola, mansione, responsabile o team"></div>
<div class="form-group"><label for="reparto">Reparto</label><select id="reparto" name="reparto"><option value="">Tutti</option><?php foreach ($reparti as $r): ?><option value="<?= (int)$r['id_reparto'] ?>" <?= $filtroReparto === (int)$r['id_reparto'] ? 'selected' : '' ?>><?= h($r['nome']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label for="centro">Centro di costo</label><select id="centro" name="centro"><option value="">Tutti</option><?php foreach ($centriCosto as $c): ?><option value="<?= (int)$c['id_centro_costo'] ?>" <?= $filtroCentro === (int)$c['id_centro_costo'] ? 'selected' : '' ?>><?= h($c['nome']) ?></option><?php endforeach; ?></select></div>
<div class="form-group"><label for="stato">Stato</label><select id="stato" name="stato"><option value="">Tutti</option><?php foreach (['attivi'=>'Account e profilo HR attivi','account_disattivo'=>'Account disattivo','hr_disattivo'=>'Profilo HR disattivo'] as $k=>$v): ?><option value="<?= h($k) ?>" <?= $filtroStato === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
</div><div class="hr-directory-actions"><button type="submit" class="btn btn-primary">Filtra</button><a class="btn btn-light" href="profili_dipendenti.php">Azzera filtri</a></div></form>
<p class="meta"><?= count($profiliVisibili) ?> persone visualizzate su <?= count($profili) ?> · <?= $profiliAttivi ?> con account e profilo HR attivi</p>
<div class="table-wrap" tabindex="0" role="region" aria-label="Elenco profili dipendenti"><table class="hr-directory-table"><thead><tr><th scope="col">Dipendente</th><th scope="col">Reparto</th><th scope="col">Centro di costo</th><th scope="col">Mansione</th><th scope="col">Responsabile / referente</th><th scope="col">Team</th><th scope="col">Stato</th><th scope="col">Scheda</th></tr></thead><tbody>
<?php foreach ($profiliVisibili as $p): $uid=(int)$p['id_utente']; ?>
<tr><td><strong><?= h(hrProfiloLabelUtente($p)) ?></strong><div class="meta"><?= h(hrProfiloDescrizioneUtente($p)) ?></div></td><td><?= h(hrProfiloValore($p['reparto'] ?? null)) ?></td><td><?= h(hrProfiloValore($p['centro_costo'] ?? null)) ?><?php if (trim((string)($p['codice_centro_costo'] ?? '')) !== ''): ?><div class="meta"><?= h($p['codice_centro_costo']) ?></div><?php endif; ?></td><td><?= h(hrProfiloValore($p['mansione'] ?? null, 'Non indicata')) ?></td>
<td><?php $rr=$responsabiliByUtente[$uid] ?? []; if (!$rr): ?>Non assegnato<?php else: foreach ($rr as $resp): ?><div><?= h($resp['label']) ?> <span class="meta"><?= h($resp['tipo']) ?></span></div><?php endforeach; endif; ?></td>
<td><?php $tt=$teamByUtente[$uid] ?? []; if (!$tt): ?>Non assegnato<?php else: foreach ($tt as $team): ?><div><?= h($team['nome']) ?><?php if ($team['ruolo'] !== ''): ?><span class="meta"> · <?= h($team['ruolo']) ?></span><?php endif; ?></div><?php endforeach; endif; ?></td>
<td><div class="meta">Account: <?= (int)$p['account_attivo'] === 1 ? 'attivo' : 'disattivo' ?></div><div class="meta">Profilo HR: <?= (int)$p['profilo_hr_attivo'] === 1 ? 'attivo' : 'disattivo' ?></div></td><td><a class="btn btn-light" href="profili_dipendenti.php?<?= h(http_build_query($parametriFiltro + ['profilo'=>(int)$p['id_profilo_dipendente']])) ?>#profilo">Apri profilo</a></td></tr>
<?php endforeach; ?>
<?php if (!$profiliVisibili): ?><tr><td colspan="8">Nessun profilo corrisponde ai filtri.</td></tr><?php endif; ?>
</tbody></table></div></section></div>
<?php layoutFooter(); ?>
