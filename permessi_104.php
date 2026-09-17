<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
richiediPermessoLettura('permessi_104');
$pdo=db();
$idUtente=(int)($_SESSION['id_utente'] ?? $_SESSION['utente_id'] ?? 0);
function h(?string $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
$anno=(int)($_GET['anno'] ?? date('Y')); $mese=(int)($_GET['mese'] ?? date('n'));
if($mese<1||$mese>12)$mese=(int)date('n'); if($anno<2020||$anno>2100)$anno=(int)date('Y');
$beneficio=null;$movimenti=[];$usatiMinuti=0;$errore='';
try{
 $stmt=$pdo->prepare("SELECT * FROM hr_benefici_utenti WHERE id_utente=:u AND codice_beneficio='LEGGE_104' AND attivo=1 AND data_inizio<=LAST_DAY(:d) AND (data_fine IS NULL OR data_fine>=:d) LIMIT 1");
 $d=sprintf('%04d-%02d-01',$anno,$mese);$stmt->execute(['u'=>$idUtente,'d'=>$d]);$beneficio=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
 if($beneficio){
  $stmt=$pdo->prepare("SELECT r.codice_richiesta,sr.codice stato,p.tipo_periodo,p.data_da,p.data_a,p.ora_da,p.ora_a,p.minuti_totali FROM hr_richieste r JOIN hr_tipologie_evento te ON te.id_tipologia_evento=r.id_tipologia_evento AND te.codice='LEGGE_104' JOIN hr_stati_richiesta sr ON sr.id_stato_richiesta=r.id_stato_richiesta JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_utente_richiedente=:u AND sr.codice IN ('APPROVATA','IN_ATTESA') AND YEAR(p.data_da)=:a AND MONTH(p.data_da)=:m ORDER BY p.data_da,p.ora_da");
  $stmt->execute(['u'=>$idUtente,'a'=>$anno,'m'=>$mese]);$movimenti=$stmt->fetchAll(PDO::FETCH_ASSOC);
  foreach($movimenti as $r){$usatiMinuti += strtoupper((string)$r['tipo_periodo'])==='GIORNI'?(int)$beneficio['minuti_giornata_equivalenza']:(int)$r['minuti_totali'];}
 }
}catch(Throwable $e){$errore=$e->getMessage();}
$plafondMinuti=$beneficio?(int)$beneficio['plafond_minuti_mese']:0;$minGiorno=$beneficio?(int)$beneficio['minuti_giornata_equivalenza']:0;$plafondGiorni=$beneficio?(float)$beneficio['plafond_giorni_mese']:0;
$residuoMinuti=max(0,$plafondMinuti-$usatiMinuti);$usatiGiorni=$minGiorno>0?$usatiMinuti/$minGiorno:0;$residuoGiorni=max(0,$plafondGiorni-$usatiGiorni);
layoutHeader('Permessi tutelati');
?>
<div class="page-container"><div class="card card-wide"><div class="section-head"><div><h1>Permessi tutelati</h1><div class="meta">Situazione personale dei permessi abilitati da HR.</div></div><a class="btn btn-light" href="assenze.php">Nuova richiesta</a></div></div>
<?php if($errore):?><div class="alert alert-error"><?=h($errore)?></div><?php endif;?>
<?php if(!$beneficio):?><div class="card"><div class="info-box">Non risultano permessi Legge 104 attivi per il tuo profilo nel periodo selezionato.</div></div>
<?php else:?>
<div class="card card-wide"><div class="section-head"><h2>Legge 104 - <?=sprintf('%02d/%04d',$mese,$anno)?></h2><form method="get"><select name="mese"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$m===$mese?'selected':''?>><?=sprintf('%02d',$m)?></option><?php endfor;?></select><input type="number" name="anno" value="<?=$anno?>" min="2020" max="2100"><button class="btn" type="submit">Vai</button></form></div>
<div class="hr-summary-line"><span><strong><?=number_format($usatiGiorni,2,',','.')?></strong> giorni equivalenti utilizzati</span><span><strong><?=number_format($residuoGiorni,2,',','.')?></strong> giorni equivalenti residui</span><span><strong><?=number_format($usatiMinuti/60,2,',','.')?></strong> ore utilizzate</span><span><strong><?=number_format($residuoMinuti/60,2,',','.')?></strong> ore residue</span></div>
<p class="meta">Plafond configurato da HR: <?=number_format($plafondGiorni,2,',','.')?> giorni e <?=number_format($plafondMinuti/60,2,',','.')?> ore al mese. Una giornata equivale a <?=number_format($minGiorno/60,2,',','.')?> ore.</p>
<?php if(!$movimenti):?><div class="meta">Nessun utilizzo nel mese selezionato.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Data</th><th>Modalità</th><th>Quantità</th><th>Stato</th><th>Codice</th></tr></thead><tbody><?php foreach($movimenti as $r):$min=strtoupper((string)$r['tipo_periodo'])==='GIORNI'?$minGiorno:(int)$r['minuti_totali'];?><tr><td><?=h(date('d/m/Y',strtotime((string)$r['data_da'])))?></td><td><?=h(ucfirst(strtolower((string)$r['tipo_periodo'])))?></td><td><?=strtoupper((string)$r['tipo_periodo'])==='GIORNI'?'1 giorno':h(number_format($min/60,2,',','.').' ore')?></td><td><?=h((string)$r['stato'])?></td><td><?=h((string)$r['codice_richiesta'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></div><?php endif;?></div>
<?php layoutFooter(); ?>