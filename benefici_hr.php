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
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$puoScrivere) throw new RuntimeException('Non hai i permessi di modifica.');
        $idUtente = (int)($_POST['id_utente'] ?? 0);
        $tipoBeneficio = strtoupper(trim((string)($_POST['tipo_beneficio'] ?? 'LEGGE_104')));
        $attivo = isset($_POST['attivo']) ? 1 : 0;
        if (!in_array($tipoBeneficio, ['LEGGE_104','SMART_WORKING'], true)) throw new RuntimeException('Beneficio non valido.');
        $dataInizio = trim((string)($_POST['data_inizio'] ?? ''));
        $dataFine = trim((string)($_POST['data_fine'] ?? ''));
        $giorni = (float)str_replace(',', '.', (string)($_POST['plafond_giorni_mese'] ?? '0'));
        $ore = (float)str_replace(',', '.', (string)($_POST['plafond_ore_mese'] ?? '0'));
        $oreGiornata = (float)str_replace(',', '.', (string)($_POST['ore_giornata_equivalenza'] ?? '0'));
        $note = trim((string)($_POST['note_hr'] ?? ''));
        if ($idUtente <= 0 || $dataInizio === '') throw new RuntimeException('Dipendente e data di decorrenza sono obbligatori.');
        if ($tipoBeneficio === 'LEGGE_104' && ($giorni <= 0 || $ore <= 0 || $oreGiornata <= 0)) throw new RuntimeException('Plafond giorni, plafond ore e ore equivalenti per giornata devono essere maggiori di zero.');
        if ($tipoBeneficio === 'SMART_WORKING') { $giorni = 0; $ore = 0; $oreGiornata = 0; }
        if ($dataFine !== '' && $dataFine < $dataInizio) throw new RuntimeException('La data finale non può precedere la data iniziale.');
        $minuti = (int)round($ore * 60);
        $minutiGiornata = (int)round($oreGiornata * 60);
        $stmt = $pdo->prepare("INSERT INTO hr_benefici_utenti (id_utente,codice_beneficio,data_inizio,data_fine,consente_giorni,consente_ore,plafond_giorni_mese,plafond_minuti_mese,minuti_giornata_equivalenza,note_hr,attivo,aggiornato_da,data_aggiornamento) VALUES (:u,:cb,:di,:df,1,1,:pg,:pm,:mg,:n,:a,:op,NOW()) ON DUPLICATE KEY UPDATE data_inizio=VALUES(data_inizio),data_fine=VALUES(data_fine),consente_giorni=1,consente_ore=1,plafond_giorni_mese=VALUES(plafond_giorni_mese),plafond_minuti_mese=VALUES(plafond_minuti_mese),minuti_giornata_equivalenza=VALUES(minuti_giornata_equivalenza),note_hr=VALUES(note_hr),attivo=VALUES(attivo),aggiornato_da=VALUES(aggiornato_da),data_aggiornamento=NOW()");
        $stmt->execute(['u'=>$idUtente,'cb'=>$tipoBeneficio,'di'=>$dataInizio,'df'=>$dataFine!==''?$dataFine:null,'pg'=>$giorni,'pm'=>$minuti,'mg'=>$minutiGiornata,'n'=>$note!==''?$note:null,'a'=>$attivo,'op'=>$idOperatore?:null]);
        $messaggio = $tipoBeneficio === 'SMART_WORKING' ? 'Abilitazione smart working aggiornata correttamente.' : 'Abilitazione Legge 104 aggiornata correttamente.';
    }

    $utenti = $pdo->query("SELECT u.id_utente,u.username,TRIM(CONCAT(COALESCE(u.nome,''),' ',COALESCE(u.cognome,''))) nominativo,b.data_inizio,b.data_fine,b.plafond_giorni_mese,b.plafond_minuti_mese,b.minuti_giornata_equivalenza,b.note_hr,b.attivo beneficio_attivo FROM aut_utenti u LEFT JOIN hr_benefici_utenti b ON b.id_utente=u.id_utente AND b.codice_beneficio='LEGGE_104' WHERE u.attivo=1 ORDER BY u.cognome,u.nome,u.username")->fetchAll(PDO::FETCH_ASSOC);
    $utentiSmart = $pdo->query("SELECT u.id_utente,u.username,TRIM(CONCAT(COALESCE(u.nome,''),' ',COALESCE(u.cognome,''))) nominativo,b.data_inizio,b.data_fine,b.note_hr,b.attivo beneficio_attivo FROM aut_utenti u LEFT JOIN hr_benefici_utenti b ON b.id_utente=u.id_utente AND b.codice_beneficio='SMART_WORKING' WHERE u.attivo=1 ORDER BY u.cognome,u.nome,u.username")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $errore = $e->getMessage(); $utenti = $utenti ?? []; }

layoutHeader('Benefici e diritti HR');
?>
<style>
.hr-benefici-table td { vertical-align: middle; }
.hr-inline-field { display:flex; align-items:center; gap:.45rem; margin:.2rem 0; white-space:nowrap; }
.hr-inline-field .hr-field-label { min-width:48px; font-weight:600; }
.hr-inline-field input[type="date"] { width:155px; }
.hr-inline-field input[type="number"] { width:90px; }
.hr-equivalenza input[type="number"] { width:90px; }
.hr-note { min-width:180px; width:100%; }
.hr-benefici-table .col-date { min-width:225px; }
.hr-benefici-table .col-plafond { min-width:180px; }
.hr-benefici-table .col-equivalenza { min-width:180px; }
@media (max-width:900px){
  .hr-inline-field{align-items:flex-start;}
  .hr-benefici-table .col-date,.hr-benefici-table .col-plafond,.hr-benefici-table .col-equivalenza{min-width:200px;}
}
</style>
<div class="page-container">
<div class="card card-wide"><div class="section-head"><div><h1>Benefici e diritti HR</h1><div class="meta">Abilitazioni individuali e plafond mensili. Informazioni riservate a HR e utenti autorizzati.</div></div><a class="btn btn-light" href="configurazione_assenze.php">Configurazione assenze</a></div></div>
<?php if($messaggio): ?><div class="alert alert-success"><?=h($messaggio)?></div><?php endif; ?>
<?php if($errore): ?><div class="alert alert-error"><?=h($errore)?></div><?php endif; ?>
<div class="card card-wide"><h2>Permessi Legge 104</h2><p class="meta">Il plafond viene controllato contemporaneamente in giorni equivalenti e in ore. I valori sono configurati da HR per il singolo dipendente.</p>
<div class="table-wrap"><table class="hr-benefici-table"><thead><tr><th>Dipendente</th><th>Decorrenza</th><th>Plafond mensile</th><th>Equivalenza giornata</th><th>Note HR</th><th>Stato</th><th>Azioni</th></tr></thead><tbody>
<?php foreach($utenti as $u): $abilitato=$u['data_inizio']!==null; ?>
<tr><form method="post"><td><strong><?=h(trim((string)$u['nominativo']) ?: (string)$u['username'])?></strong><input type="hidden" name="id_utente" value="<?=(int)$u['id_utente']?>"></td>
<td class="col-date"><div class="hr-inline-field"><span class="hr-field-label">DA:</span><input type="date" name="data_inizio" value="<?=h((string)($u['data_inizio'] ?? date('Y-m-d')))?>"></div><div class="hr-inline-field"><span class="hr-field-label">A:</span><input type="date" name="data_fine" value="<?=h((string)($u['data_fine'] ?? ''))?>"></div></td>
<td class="col-plafond"><div class="hr-inline-field"><span class="hr-field-label">Giorni:</span><input type="number" min="0.01" step="0.01" name="plafond_giorni_mese" value="<?=h((string)($u['plafond_giorni_mese'] ?? '3'))?>"></div><div class="hr-inline-field"><span class="hr-field-label">Ore:</span><input type="number" min="0.01" step="0.01" name="plafond_ore_mese" value="<?=h($abilitato ? number_format(((int)$u['plafond_minuti_mese'])/60,2,'.','') : '24.00')?>"></div></td>
<td class="col-equivalenza"><div class="hr-inline-field hr-equivalenza"><span class="hr-field-label">Ore:</span><input type="number" min="0.01" step="0.01" name="ore_giornata_equivalenza" value="<?=h($abilitato ? number_format(((int)$u['minuti_giornata_equivalenza'])/60,2,'.','') : '8.00')?>"></div></td>
<td><textarea class="hr-note" name="note_hr" rows="2"><?=h((string)($u['note_hr'] ?? ''))?></textarea></td>
<td><label><input type="checkbox" name="attivo" value="1" <?=((int)($u['beneficio_attivo'] ?? 0)===1)?'checked':''?>> abilitato</label></td>
<td><?php if($puoScrivere): ?><button class="btn btn-primary" type="submit">Salva</button><?php else: ?><span class="meta">Sola lettura</span><?php endif; ?></td></form></tr>
<?php endforeach; ?></tbody></table></div></div>
<div class="card card-wide"><h2>Smart working</h2><p class="meta">Abilita individualmente i dipendenti autorizzati a richiedere smart working. La voce comparirà nel menu richieste solo agli utenti abilitati.</p>
<div class="table-wrap"><table class="hr-benefici-table"><thead><tr><th>Dipendente</th><th>Decorrenza</th><th>Note HR</th><th>Stato</th><th>Azioni</th></tr></thead><tbody>
<?php foreach($utentiSmart as $u): ?>
<tr><form method="post"><td><strong><?=h(trim((string)$u['nominativo']) ?: (string)$u['username'])?></strong><input type="hidden" name="id_utente" value="<?=(int)$u['id_utente']?>"><input type="hidden" name="tipo_beneficio" value="SMART_WORKING"></td>
<td class="col-date"><div class="hr-inline-field"><span class="hr-field-label">DA:</span><input type="date" name="data_inizio" value="<?=h((string)($u['data_inizio'] ?? date('Y-m-d')))?>"></div><div class="hr-inline-field"><span class="hr-field-label">A:</span><input type="date" name="data_fine" value="<?=h((string)($u['data_fine'] ?? ''))?>"></div></td>
<td><textarea class="hr-note" name="note_hr" rows="2"><?=h((string)($u['note_hr'] ?? ''))?></textarea><input type="hidden" name="plafond_giorni_mese" value="0"><input type="hidden" name="plafond_ore_mese" value="0"><input type="hidden" name="ore_giornata_equivalenza" value="0"></td>
<td><label><input type="checkbox" name="attivo" value="1" <?=((int)($u['beneficio_attivo'] ?? 0)===1)?'checked':''?>> abilitato</label></td>
<td><?php if($puoScrivere): ?><button class="btn btn-primary" type="submit">Salva</button><?php else: ?><span class="meta">Sola lettura</span><?php endif; ?></td></form></tr>
<?php endforeach; ?></tbody></table></div></div></div>
<?php layoutFooter(); ?>