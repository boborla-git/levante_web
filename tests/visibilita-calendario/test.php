<?php
require dirname(__DIR__).'/profili-dipendenti/fixture.php';
require dirname(__DIR__,2).'/includes/hr_organizzazione.php';
require dirname(__DIR__,2).'/includes/hr_calendario.php';
require dirname(__DIR__,2).'/includes/hr_scope.php';
class VisPDO extends OrgPDO {
 public array $events=[];
 public function __construct(){parent::__construct();$this->today='2026-10-13';}
 public function quote(string $s,int $type=PDO::PARAM_STR):string|false{return "'".str_replace("'","''",$s)."'";}
 public function prepare(string $q,array $options=[]):PDOStatement|false{return new VisStmt($this,$q);}
}
class VisStmt extends OrgStmt {
 public function execute(?array $p=null):bool{
  $p=$p??[];$d=$this->db;$s=preg_replace('/\s+/',' ',trim($this->sql));$this->rows=[];
  if(str_starts_with($s,'SELECT codice FROM hr_tipi')&&$p['id']===3){$this->rows=[['codice'=>'VISIBILITA_CALENDARIO']];return true;}
  if(str_starts_with($s,'SELECT * FROM hr_relazioni_organizzative')){
   foreach($d->relations as $r)if($r['id_utente']===$p['utente']&&$r['id_utente_collegato']===$p['referente']&&$r['id_tipo_relazione']===$p['tipo']&&$r['attiva']&&($p['fine']===null||$r['data_inizio']<=$p['fine'])&&($r['data_fine']===null||$r['data_fine']>=$p['inizio']))$this->rows[]=$r;
   return true;
  }
  if(str_starts_with($s,'SELECT DISTINCT ro.id_utente')){
   $vis=str_contains($s,"tro.codice = 'VISIBILITA_CALENDARIO'");
   foreach($d->relations as $r)if($r['id_utente_collegato']===$p['id_utente']&&$r['attiva']&&$r['data_inizio']<=$d->today&&($r['data_fine']===null||$r['data_fine']>=$d->today)&&($vis?$r['id_tipo_relazione']===3:in_array($r['id_tipo_relazione'],[1,2],true)))$this->rows[]=['id_utente'=>$r['id_utente']];
   return true;
  }
  if(str_starts_with($s,'SELECT DISTINCT gu2.id_utente'))return true;
  if(str_starts_with($s,'SELECT id_utente, nome, cognome, username')){
   foreach($d->users as $u)if($u['attivo']&&(!str_contains($s,' IN (')||in_array($u['id_utente'],$p,true)))$this->rows[]=$u;return true;
  }
  if(str_starts_with($s,'SELECT r.id_richiesta, r.codice_richiesta')){
   $ids=array_slice($p,0,count($p)-5);$owner=$p[count($p)-3];$pending=$p[count($p)-2];
   foreach($d->events as $e)if(in_array($e['id_utente_richiedente'],$ids,true)&&($e['codice_stato_richiesta']==='APPROVATA'||$pending||$e['id_utente_richiedente']===$owner))$this->rows[]=$e;
   $d->queries[]=$s;return true;
  }
  return parent::execute($p);
 }
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $mode===PDO::FETCH_COLUMN?array_map(fn($r)=>array_values($r)[0],$this->rows):$this->rows;}
}
$n=0;function ok(bool $x,string $label):void{global $n;if(!$x)throw new RuntimeException($label);$n++;}
function linkVis(VisPDO $d,int $a=1,int $b=2,string $start='2026-10-13',?string $end=null,int $type=3):void{$d->beginTransaction();try{hrOrgAssegnaVisibilitaCalendario($d,$a,$b,$type,$start,$end,'Visibilità');$d->commit();}catch(Throwable $e){$d->rollBack();throw $e;}}
function fails(callable $fn,string $label):void{try{$fn();}catch(RuntimeException $e){ok(true,$label);return;}ok(false,$label);}
$d=new VisPDO();$d->relations=[1=>orgRow(1,3)];$boss=$d->relations[1];linkVis($d);
ok($d->relations[1]===$boss,'Actual boss must not change');ok(count($d->relations)===2,'Separate visibility relation');
linkVis($d);ok(count($d->relations)===2,'Repeat idempotent');linkVis($d,1,3);ok(count($d->relations)===3,'Multiple viewers allowed');
fails(fn()=>linkVis($d,1,2,'2026-10-14'),'Overlapping same pair denied');
$before=$d->relations;fails(fn()=>linkVis($d,1,1),'Self denied');ok($d->relations===$before,'Rollback');fails(fn()=>linkVis($d,1,2,'2026-02-30'),'Invalid date');fails(fn()=>linkVis($d,1,2,'2026-11-01','2026-10-01'),'Reversed dates');fails(fn()=>linkVis($d,1,4),'Inactive viewer');fails(fn()=>linkVis($d,1,2,'2026-11-01',null,1),'Wrong type');
$b=hrScopeUtentiCalendario($d,2,false);$a=hrScopeUtentiCalendario($d,1,false);
ok(in_array(1,array_column($b,'id_utente'),true),'B sees A');ok(!in_array(2,array_column($a,'id_utente'),true),'A does not see B');
ok(hrScopeDirectReportIds($d,2)===[],'No managerial perimeter');ok(hrIdsDirettiCalendario($d,2)===[],'No boss assignment');
foreach($b as $u)if($u['id_utente']===1)ok($u['scope_calendario']===1&&!$u['scope_gerarchia'],'Visibility badge only');
$future=new VisPDO();linkVis($future,1,2,'2026-10-14');ok(hrIdsVisibilitaCalendario($future,2)===[],'Future excluded');
$expired=new VisPDO();linkVis($expired,1,2,'2026-10-01','2026-10-12');ok(hrIdsVisibilitaCalendario($expired,2)===[],'Expired excluded');
$bounded=new VisPDO();linkVis($bounded,1,2,'2026-10-13','2026-10-13');ok(hrIdsVisibilitaCalendario($bounded,2)===[1],'Both ends inclusive');
$bounded->beginTransaction();hrOrgChiudiRelazione($bounded,100);$bounded->commit();ok(hrIdsVisibilitaCalendario($bounded,2)===[],'Revoked excluded');
$chain=new VisPDO();linkVis($chain,1,2);linkVis($chain,2,3);ok(!in_array(1,array_column(hrScopeUtentiCalendario($chain,3,false),'id_utente'),true),'No transitive visibility');
$map=[1=>['gerarchia'=>false,'gruppo'=>false,'calendario'=>true]];
$e=['id_utente_richiedente'=>1,'codice_tipologia'=>'FERIE','stato_presenza_breve'=>'Assente','stato_presenza'=>'Assente','descrizione_calendario'=>'Ferie','tipologia'=>'Ferie','mostra_dettaglio_responsabili'=>1,'mostra_dettaglio_hr'=>1,'mostra_dettaglio_colleghi'=>1,'codice_stato_richiesta'=>'APPROVATA'];
foreach(['FERIE','PERMESSO','LEGGE_104','MALATTIA','ALLATTAMENTO','CONGEDO_STRAORDINARIO_DISABILI','ALTRO']as $code){$r=$e;$r['codice_tipologia']=$code;ok(!hrMostraDettaglioCalendario($r,$map,2,true,true,false),'Personal always private '.$code);ok(hrEtichettaCalendario($r,$map,2,true,true,false)==='Assente','Label private '.$code);}
foreach(['VISITA_CLIENTE','VISITA_FORNITORE','FORMAZIONE','FIERA','TRASFERTA','SMART']as $code){$r=$e;$r['codice_tipologia']=$code;ok(hrMostraDettaglioCalendario($r,$map,2,false,false,false),'Work details '.$code);}
$d->events=[$e,$e+[]];$d->events[1]['codice_stato_richiesta']='IN_ATTESA';$r=hrEventiCalendario($d,2,[1,2],false,'2026-10-13','2026-10-13');ok(count($r)===1,'Visibility adds approved only');
ok(hrMostraDettaglioCalendario($e,$map,2,true,true,true),'HR rights preserved');
ok(hrMostraDettaglioCalendario($e,[],1,false,false,false),'Owner rights preserved');
echo $n.' controlli PASS: servizio, direzione, date, revoca, approvazioni separate e privacy.';

$src=file_get_contents(dirname(__DIR__,2).'/calendario_personale_ics.php');$i=strpos($src,'function icsPresentazioneEvento(');$j=strpos($src,'$pdo = db();',$i);eval(substr($src,$i,$j-$i));
$event=$e+['nome'=>'Persona','cognome'=>'Prova','username'=>'Demo','stato_richiesta'=>'Approvata','oggetto'=>'DETTAGLIO RISERVATO','codice_richiesta'=>'HR-DEMO'];
$p=icsPresentazioneEvento($event,$map,2,true,true,false);
ok(str_contains($p['titolo'],'Assente')&&!str_contains(json_encode($p),'DETTAGLIO')&&$p['categorie']==='LEVANTE','ICS personal masked');
$event['codice_tipologia']='VISITA_CLIENTE';$event['descrizione_calendario']='Visita cliente';
$p=icsPresentazioneEvento($event,$map,2,false,false,false);ok(str_contains($p['titolo'],'Visita cliente')&&str_contains($p['descrizione'],'DETTAGLIO'),'ICS work visible');
echo "\nICS presentazione PASS.";
