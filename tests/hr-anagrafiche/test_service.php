<?php
$root=dirname(__DIR__);if(basename($root)==='tests')$root=dirname($root);
require_once $root.'/includes/hr_anagrafiche.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
class AnagraficaPDO extends PDO {
 public array $tables=[];public array $snapshot=[];public array $writes=[];public bool $tx=false;public bool $lock=false;public bool $unavailable=false;public bool $failInsert=false;public int $last=0;public int $released=0;
 public function __construct(){foreach(['hr_reparti','hr_centri_costo'] as $t)$this->tables[$t]=[['id'=>1,'codice'=>'DIR','nome'=>'Direzione','ordinamento'=>10,'attivo'=>1],['id'=>2,'codice'=>'OLD','nome'=>'Vecchia voce','ordinamento'=>20,'attivo'=>0]];}
 public function prepare(string $q,array $options=[]):PDOStatement|false{return new AnagraficaStatement($this,$q);}
 public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{$s=$this->prepare($q);$s->execute();return $s;}
 public function inTransaction():bool{return $this->tx;}
 public function beginTransaction():bool{$this->snapshot=$this->tables;$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;return true;}
 public function rollBack():bool{$this->tables=$this->snapshot;$this->tx=false;return true;}
 public function lastInsertId(?string $name=null):string|false{return (string)$this->last;}
}
class AnagraficaStatement extends PDOStatement {
 private array $rows=[];
 public function __construct(private AnagraficaPDO $db,private string $q){}
 public function execute(?array $p=null):bool{
  $p=$p??[];$q=$this->q;$this->rows=[];
  if(str_contains($q,'information_schema.COLUMNS'))$this->rows=[['COLUMN_NAME'=>'codice','CHARACTER_MAXIMUM_LENGTH'=>6],['COLUMN_NAME'=>'nome','CHARACTER_MAXIMUM_LENGTH'=>80]];
  elseif(str_contains($q,'SELECT DATABASE()'))$this->rows=[['fixture_db']];
  elseif(str_contains($q,'GET_LOCK')){$this->db->lock=!$this->db->unavailable;$this->rows=[[$this->db->lock?1:0]];check(strlen($p['nome_lock'])<=64,'lock name length');}
  elseif(str_contains($q,'RELEASE_LOCK')){$this->db->lock=false;$this->db->released++;$this->rows=[[1]];}
  else {
   preg_match('/(?:FROM|INTO) (hr_reparti|hr_centri_costo)/',$q,$m);$t=$m[1]??'';
   if(str_contains($q,'UPPER(TRIM(codice))')){foreach($this->db->tables[$t] as $r)if(strtoupper(trim($r['codice']))===$p['codice']){$this->rows=[[$r['id']]];break;}}
   elseif(str_contains($q,'MAX(ordinamento)'))$this->rows=[[max(array_column($this->db->tables[$t],'ordinamento'))]];
   elseif(str_starts_with($q,'INSERT INTO')){check($this->db->tx && $this->db->lock,'insert transaction and lock');if($this->db->failInsert)throw new PDOException('Fixture insert failure');$this->db->last=3;$this->db->tables[$t][]=['id'=>3]+$p+['attivo'=>1];$this->db->writes[]=$q;}
   elseif(str_contains($q,'AS id_voce')){$this->rows=array_map(fn($r)=>['id_voce'=>$r['id']]+$r,$this->db->tables[$t]);}
   else throw new Exception('Unexpected SQL: '.$q);
  }
  return true;
 }
 public function fetchColumn(int $c=0):mixed{return isset($this->rows[0])?array_values($this->rows[0])[$c]:false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
if(!defined('ANAGRAFICA_TEST_FIXTURE_ONLY')) {
 foreach(['reparti'=>'hr_reparti','centri_costo'=>'hr_centri_costo'] as $tipo=>$t){
  $db=new AnagraficaPDO();$before=$db->tables;
  check(hrAnagraficaCrea($db,$tipo,['codice'=>' amm01 ','nome'=>' Amministrazione '])===3,'new id');
  check($db->tables[$t][2]===['id'=>3,'codice'=>'AMM01','nome'=>'Amministrazione','ordinamento'=>30,'attivo'=>1],'new data');
  foreach($before as $table=>$rows)check(array_slice($db->tables[$table],0,2)===$rows,'existing rows unchanged');
  check(!$db->tx && !$db->lock && $db->released===1,'commit and release');
  foreach(['dir','OLD'] as $dup){$before=$db->tables;try{hrAnagraficaCrea($db,$tipo,['codice'=>$dup,'nome'=>'Duplicato']);throw new LogicException('duplicate accepted');}catch(RuntimeException $e){check(!($e instanceof LogicException),'duplicate rejection');}check($db->tables===$before && !$db->lock && !$db->tx,'duplicate preserves data');}
  $db=new AnagraficaPDO();$db->failInsert=true;$before=$db->tables;
  try{hrAnagraficaCrea($db,$tipo,['codice'=>'NEW','nome'=>'New']);throw new LogicException('failed insert accepted');}catch(PDOException $e){}
  check($db->tables===$before && !$db->tx && !$db->lock && $db->released===1,'rollback failure');
  $db=new AnagraficaPDO();$db->unavailable=true;try{hrAnagraficaCrea($db,$tipo,['codice'=>'NEW','nome'=>'New']);throw new LogicException('unavailable lock accepted');}catch(RuntimeException $e){check(!($e instanceof LogicException),'lock rejection');}check(!$db->writes,'no insert without lock');
  echo "$tipo: create, duplicates including inactive, rollback, lock PASS\n";
 }
 foreach([['codice'=>'','nome'=>'Nome'],['codice'=>'AB','nome'=>''],['codice'=>'X;DROP','nome'=>'Nome'],['codice'=>'TOOLONG','nome'=>'Nome'],['codice'=>'OK','nome'=>str_repeat('è',81)],['codice'=>'OK','nome'=>"Nome\naltro"]] as $invalid){try{hrAnagraficaValida($invalid,['codice'=>6,'nome'=>80]);throw new LogicException('invalid accepted');}catch(RuntimeException $e){check(!($e instanceof LogicException),'validation');}}
 check(hrAnagraficaValida(['codice'=>' OK ','nome'=>str_repeat('è',80)],['codice'=>6,'nome'=>80])['nome']===str_repeat('è',80),'UTF8 char count');
 try{hrAnagraficaConfig('aut_utenti');throw new LogicException('unsafe table');}catch(InvalidArgumentException $e){}
 echo "Validation, UTF8, table whitelist PASS\n";
}
