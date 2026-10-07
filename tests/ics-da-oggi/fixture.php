<?php
class IcsPDO extends PDO {
 public array $queryParams=[];public array $events=[];
 public function __construct(){
  $today=new DateTimeImmutable('today',new DateTimeZone('Europe/Rome'));$t=$today->format('Y-m-d');$y=$today->modify('-1 day')->format('Y-m-d');$f=$today->modify('+1 day')->format('Y-m-d');
  foreach([[1,$y,$y,1,'APPROVATA'],[2,$t,$t,1,'APPROVATA'],[3,$f,$f,2,'APPROVATA'],[4,$y,$f,1,'APPROVATA'],[5,$t,$t,99,'APPROVATA'],[6,$t,$t,1,'IN_ATTESA'],[7,$t,$t,2,'IN_ATTESA'],[8,$t,$t,2,'IN_ATTESA'],[9,$y,$t,1,'APPROVATA'],[10,$y,$y,1,'APPROVATA'],[11,$f,$f,1,'APPROVATA']]as $row){
   [$id,$from,$to,$person,$state]=$row;
   $this->events[]=['id_richiesta'=>$id===11?10:$id,'id_richiesta_periodo'=>$id,'id_utente_richiedente'=>$person,'codice_richiesta'=>'DEMO-'.$id,'data_da'=>$from,'data_a'=>$to,'ora_da'=>$id===2?'08:00:00':null,'ora_a'=>$id===2?'09:00:00':null,'tipo_periodo'=>$id===2?'ORE':'GIORNI','data_aggiornamento'=>'2026-01-01 10:00:00','data_creazione'=>'2026-01-01 10:00:00','codice_stato_richiesta'=>$state,'stato_richiesta'=>$state,'codice_tipologia'=>'FERIE','tipologia'=>'Ferie','descrizione_calendario'=>'Ferie','stato_presenza_breve'=>'Assente','stato_presenza'=>'Assente','nome'=>$person===1?'Mario':'Anna','cognome'=>$person===1?'Rossi':'Bianchi','username'=>'Demo','oggetto'=>'Motivo riservato','mostra_dettaglio_colleghi'=>0,'mostra_dettaglio_responsabili'=>1,'mostra_dettaglio_hr'=>1];
  }
 }
 public function prepare(string $q,array $options=[]):PDOStatement|false{return new IcsStmt($this,$q);}
}
class IcsStmt extends PDOStatement {
 public array $rows=[];public function __construct(public IcsPDO $d,public string $sql){}
 public function execute(?array $p=null):bool{
  $p=$p??[];$this->rows=[];
  if(str_contains($this->sql,'FROM aut_utenti')&&str_contains($this->sql,'username = :username'))$this->rows=[['id_utente'=>1,'username'=>'Demo','nome'=>'Mario','cognome'=>'Rossi']];
  elseif(str_contains($this->sql,'FROM hr_configurazioni'))$this->rows=[['valore'=>($GLOBALS['scenario']??'')==='revoked'?'':hash('sha256','fixture-token')]];
  elseif(str_contains($this->sql,'FROM hr_richieste r')){
   $this->d->queryParams=$p;$ids=array_slice($p,0,count($p)-5);[$upper,$lower,$owner,$all]=$a=array_slice($p,-5);
   if(!str_contains($this->sql,'p.data_da <= ? AND p.data_a >= ?'))throw new RuntimeException('Missing real query filter');
   foreach($this->d->events as $e){if(in_array($e['id_utente_richiedente'],$ids)&&$e['data_da']<=$upper&&$e['data_a']>=$lower&&($e['codice_stato_richiesta']==='APPROVATA'||$e['id_utente_richiedente']===$owner||$all===1||$e['id_richiesta_periodo']===8))$this->rows[]=$e;}
  }else throw new RuntimeException('Unknown SQL');return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return $this->rows[0]??false;}
 public function fetchColumn(int $column=0):mixed{return $this->rows?array_values($this->rows[0])[$column]:false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
function db(){return $GLOBALS['fixture_db'];}
function hrCalendarioPermessiUtente(PDO $pdo,int $id):array{return ['leggere'=>($GLOBALS['scenario']??'')!=='denied','tutte'=>false,'pendenti'=>false,'configurare'=>false,'tipologie'=>false];}
function hrScopeUtentiCalendario(PDO $pdo,int $id,bool $all):array{if(($GLOBALS['scenario']??'')==='failure')throw new RuntimeException('DB unavailable');return [['id_utente'=>1,'nome'=>'Mario','cognome'=>'Rossi'],['id_utente'=>2,'nome'=>'Anna','cognome'=>'Bianchi','scope_gruppo'=>1]];}
