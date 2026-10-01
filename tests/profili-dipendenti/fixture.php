<?php
class OrgPDO extends PDO {
 public array $users; public array $profiles; public array $relations=[]; public array $writes=[]; public array $queries=[]; public array $snapshot=[]; public bool $tx=false; public bool $failInsert=false; public string $today='2026-10-01'; public int $next=100;
 public function __construct(){
  $this->users=[1=>['id_utente'=>1,'username'=>'MRossi','nome'=>'Mario','cognome'=>'Rossi','attivo'=>1],2=>['id_utente'=>2,'username'=>'LVerdi','nome'=>'Luca','cognome'=>'Verdi','attivo'=>1],3=>['id_utente'=>3,'username'=>'ABianchi','nome'=>'Anna','cognome'=>'Bianchi','attivo'=>1],4=>['id_utente'=>4,'username'=>'Disattivo','nome'=>'Ex','cognome'=>'Utente','attivo'=>0]];
  $this->profiles=[1=>['id_profilo_dipendente'=>1,'id_utente'=>1,'matricola'=>'10','mansione'=>'Tecnico','id_reparto'=>1,'id_centro_costo'=>1,'data_assunzione'=>null,'data_cessazione'=>null,'note_hr'=>null,'attivo'=>1]];
 }
 public function prepare(string $q,array $options=[]):PDOStatement|false{return new OrgStmt($this,$q);}
 public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{$s=$this->prepare($q);$s->execute();return $s;}
 public function exec(string $q):int|false{$s=$this->prepare($q);$s->execute();return count($this->profiles);}
 public function beginTransaction():bool{$this->snapshot=[$this->profiles,$this->relations,$this->writes];$this->tx=true;return true;}
 public function inTransaction():bool{return $this->tx;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{[$this->profiles,$this->relations,$this->writes]=$this->snapshot;$this->tx=false;return true;}
}
class OrgStmt extends PDOStatement {
 public array $rows=[];public function __construct(public OrgPDO $db,public string $sql){}
 public function execute(?array $p=null):bool{
  $p=$p??[];$d=$this->db;$s=preg_replace('/\s+/',' ',trim($this->sql));$d->queries[]=$s;$this->rows=[];
  if(str_starts_with($s,'SELECT CURDATE()')){$this->rows=[[$d->today]];}
  elseif(str_starts_with($s,'SELECT id_utente FROM aut_utenti')){$u=$d->users[$p['id']]??null;if($u&&(!str_contains($s,'attivo = 1')||$u['attivo']))$this->rows=[['id_utente'=>$u['id_utente']]];}
  elseif(str_starts_with($s,'SELECT codice FROM hr_tipi')){if(in_array($p['id'],[1,2]))$this->rows=[['codice'=>$p['id']===1?'RESPONSABILE_FUNZIONALE':'RESPONSABILE_DIRETTO']];}
  elseif(str_starts_with($s,'SELECT ro.* FROM hr_relazioni')){$this->rows=array_values(array_filter($d->relations,fn($r)=>$r['id_utente']===$p['utente']&&$r['attiva']===1&&in_array($r['id_tipo_relazione'],[1,2])));}
  elseif(str_starts_with($s,'UPDATE hr_relazioni_organizzative SET data_fine')){$d->relations[$p['id']]['data_fine']=$p['fine'];$d->writes[]=$s;}
  elseif(str_starts_with($s,'UPDATE hr_relazioni_organizzative SET attiva = 0')){$r=&$d->relations[$p['id']];$r['attiva']=0;if(str_contains($s,'CASE WHEN')&&$r['data_inizio']<=$d->today&&($r['data_fine']===null||$r['data_fine']>$d->today))$r['data_fine']=$d->today;$d->writes[]=$s;}
  elseif(str_starts_with($s,'INSERT INTO hr_relazioni')){if($d->failInsert)throw new PDOException('Forced insert failure');$id=$d->next++;$d->relations[$id]=['id_relazione_organizzativa'=>$id,'id_utente'=>$p['utente'],'id_utente_collegato'=>$p['responsabile'],'id_tipo_relazione'=>$p['tipo'],'data_inizio'=>$p['inizio'],'data_fine'=>$p['fine'],'attiva'=>1,'note'=>$p['note']];$d->writes[]=$s;}
  elseif(str_starts_with($s,'SELECT id_utente FROM hr_relazioni')){if(isset($d->relations[$p['id']]))$this->rows=[['id_utente'=>$d->relations[$p['id']]['id_utente']]];}
  elseif(str_starts_with($s,'SELECT p.id_utente FROM hr_profili')){$r=$d->profiles[$p['id']]??null;if($r&&$d->users[$r['id_utente']]['attivo'])$this->rows=[['id_utente'=>$r['id_utente']]];}
  elseif(str_starts_with($s,'SELECT id_tipo_relazione')){$this->rows=[['id_tipo_relazione'=>1]];}
  elseif(str_starts_with($s,'SELECT COUNT(*) FROM aut_utenti WHERE')){$this->rows=[[isset($d->users[$p['id_utente']])&&$d->users[$p['id_utente']]['attivo']?1:0]];}
  elseif(str_starts_with($s,'SELECT COUNT(*) FROM hr_reparti')||str_starts_with($s,'SELECT COUNT(*) FROM hr_centri_costo')){$this->rows=[[$p['id']===1?1:0]];}
  elseif(str_starts_with($s,'SELECT COUNT(*) FROM aut_utenti u')){$missing=0;foreach($d->users as $u){if($u['attivo']&&!array_filter($d->profiles,fn($r)=>$r['id_utente']===$u['id_utente']))$missing++;}$this->rows=[[$missing]];}
  elseif(str_starts_with($s,'INSERT INTO hr_profili_dipendenti')){foreach($d->users as $u){if($u['attivo']&&!array_filter($d->profiles,fn($r)=>$r['id_utente']===$u['id_utente'])){$id=$d->next++;$d->profiles[$id]=['id_profilo_dipendente'=>$id,'id_utente'=>$u['id_utente'],'attivo'=>1];}}$d->writes[]=$s;}
  elseif(str_starts_with($s,'UPDATE hr_profili_dipendenti')){$id=$p['id_profilo_dipendente'];foreach(['matricola','mansione','id_reparto','id_centro_costo','data_assunzione','data_cessazione','note_hr','attivo']as $k)$d->profiles[$id][$k]=$p[$k];$d->writes[]=$s;}
  elseif(str_starts_with($s,'SELECT id_reparto')){$this->rows=[['id_reparto'=>1,'nome'=>'Tecnico','codice'=>'TEC']];}
  elseif(str_starts_with($s,'SELECT id_centro_costo')){$this->rows=[['id_centro_costo'=>1,'nome'=>'Tecnico','codice'=>'TEC01']];}
  elseif(str_starts_with($s,'SELECT id_utente, username')){foreach($d->users as $u){if($u['attivo'])$this->rows[]=array_merge($u,['nominativo'=>$u['nome'].' '.$u['cognome']]);}}
  elseif(str_starts_with($s,'SELECT v.*')){foreach($d->profiles as $r){$u=$d->users[$r['id_utente']];$this->rows[]=array_merge(['matricola'=>null,'mansione'=>null,'id_reparto'=>null,'id_centro_costo'=>null,'data_assunzione'=>null,'data_cessazione'=>null,'note_hr'=>null],$u,$r,['account_attivo'=>$u['attivo'],'profilo_hr_attivo'=>$r['attivo'],'profilo_attivo'=>$r['attivo'],'utente_test'=>0,'reparto'=>($r['id_reparto']??null)?'Tecnico':null,'centro_costo'=>($r['id_centro_costo']??null)?'Tecnico':null,'codice_centro_costo'=>($r['id_centro_costo']??null)?'TEC01':null]);}}
  elseif(str_starts_with($s,'SELECT ro.id_utente,')){foreach($d->relations as $r){if($r['attiva']&&$r['data_inizio']<=$d->today&&($r['data_fine']===null||$r['data_fine']>=$d->today)){$u=$d->users[$r['id_utente_collegato']];$this->rows[]=array_merge($r,['tipo_codice'=>'RESPONSABILE_FUNZIONALE','tipo_descrizione'=>'Responsabile funzionale','responsabile_nome'=>$u['nome'].' '.$u['cognome'],'responsabile_username'=>$u['username']]);}}}
  elseif(str_starts_with($s,'SELECT gu.id_utente,')){}
  elseif(str_starts_with($s,'SELECT * FROM hr_tipi')){$this->rows=[['id_tipo_relazione'=>1,'codice'=>'RESPONSABILE_FUNZIONALE','descrizione'=>'Responsabile funzionale']];}
  elseif(str_starts_with($s,'SELECT ro.*, CASE WHEN')){foreach($d->relations as $r){$u=$d->users[$r['id_utente']];$c=$d->users[$r['id_utente_collegato']];$this->rows[]=array_merge($r,['vigente'=>(int)($r['attiva']&&$r['data_inizio']<=$d->today&&($r['data_fine']===null||$r['data_fine']>=$d->today)),'futura'=>(int)($r['data_inizio']>$d->today),'codice'=>'RESPONSABILE_FUNZIONALE','tipo_relazione'=>'Responsabile funzionale','utente_username'=>$u['username'],'utente_nome'=>$u['nome'],'utente_cognome'=>$u['cognome'],'collegato_username'=>$c['username'],'collegato_nome'=>$c['nome'],'collegato_cognome'=>$c['cognome'],'utente'=>$u['nome'],'utente_collegato'=>$c['nome']]);}}
  else throw new RuntimeException('Unhandled SQL: '.$s);
  return true;
 }
 public function fetchColumn(int $column=0):mixed{return $this->rows?array_values($this->rows[0])[$column]:false;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return array_shift($this->rows)??false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
function orgRow(int $id,int $boss=2,string $start='2026-01-01',?string $end=null,int $active=1,int $type=1):array{return ['id_relazione_organizzativa'=>$id,'id_utente'=>1,'id_utente_collegato'=>$boss,'id_tipo_relazione'=>$type,'data_inizio'=>$start,'data_fine'=>$end,'attiva'=>$active,'note'=>'Original note'];}
