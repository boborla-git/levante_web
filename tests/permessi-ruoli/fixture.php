<?php
declare(strict_types=1);
$case=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
$root=dirname(__DIR__,2);
function sourcePhp(string $path):string { return str_replace('declare(strict_types=1);','',preg_replace('/^require_once .*;\s*$/m','',file_get_contents($path))); }
ini_set('session.save_path',sys_get_temp_dir());session_start();
function renderAdminSaveActions(...$x):void {}
function renderAdminTabs(...$x):void {}
function renderAdminAlert(...$x):void {}
function layoutHeader(...$x):void {}
function layoutFooter(...$x):void {}
function mb_strtolower(string $s,string $e='UTF-8'):string {return strtolower($s);}
class PermPDO extends PDO {
 public array $map, $snapshot=[], $writes=[];public bool $tx=false, $committed=false, $rolledBack=false;
 public function __construct(public array $case){$this->map=[7=>[2=>['read'=>0,'write'=>0,'view'=>1,'edit'=>1,'menu'=>1]],8=>[2=>['read'=>1,'write'=>0,'view'=>1,'edit'=>0,'menu'=>1]]];}
 public function prepare(string $q,array $options=[]):PDOStatement|false{return new PermStmt($this,$q);}
 public function query(string $q,?int $mode=null,mixed ...$args):PDOStatement|false{$s=$this->prepare($q);$s->execute();return $s;}
 public function beginTransaction():bool{$this->snapshot=$this->map;$this->tx=true;return true;}
 public function commit():bool{$this->tx=false;$this->committed=true;return true;}
 public function rollBack():bool{$this->map=$this->snapshot;$this->tx=false;$this->rolledBack=true;return true;}
 public function inTransaction():bool{return $this->tx;}
}
class PermStmt extends PDOStatement {
 public array $rows=[];
 public function __construct(public PermPDO $d,public string $q){}
 public function execute(?array $p=null):bool {
  $p=$p??[];$q=preg_replace('/\s+/',' ',trim($this->q));$this->rows=[];
  if(str_starts_with($q,'INSERT INTO aut_log_accessi'))return true;
  if(str_starts_with($q,'SELECT id_ruolo, codice_ruolo'))$this->rows=[['id_ruolo'=>7,'codice_ruolo'=>'operativo_ordini','descrizione'=>'Operativo ordini','attivo'=>1,'ordinamento'=>1],['id_ruolo'=>8,'codice_ruolo'=>'altro','descrizione'=>'Altro','attivo'=>1,'ordinamento'=>2]];
  elseif(str_starts_with($q,'SELECT id_risorsa,'))$this->rows=[['id_risorsa'=>1,'codice_risorsa'=>'menu.hr','descrizione'=>'HR','tipo_risorsa'=>'menu','id_risorsa_padre'=>null,'percorso'=>'','icona'=>'','visibile_menu'=>1,'ordinamento'=>1,'attivo'=>1],['id_risorsa'=>2,'codice_risorsa'=>'pagina.assenze','descrizione'=>'Assenze','tipo_risorsa'=>'pagina','id_risorsa_padre'=>1,'percorso'=>'/assenze.php','icona'=>'','visibile_menu'=>1,'ordinamento'=>2,'attivo'=>1],['id_risorsa'=>3,'codice_risorsa'=>'azione.hr.riservata','descrizione'=>'Azione riservata','tipo_risorsa'=>'azione','id_risorsa_padre'=>2,'percorso'=>'','icona'=>'','visibile_menu'=>0,'ordinamento'=>3,'attivo'=>1]];
  elseif(str_starts_with($q,'SELECT id_ruolo, id_risorsa, permesso')){foreach($this->d->map as $role=>$resources)foreach($resources as $id=>$perms)foreach($perms as $perm=>$v)$this->rows[]=['id_ruolo'=>$role,'id_risorsa'=>$id,'permesso'=>$perm,'consentito'=>$v];}
  elseif(str_starts_with($q,'SELECT MAX(CASE WHEN')){
   $name=$p[':permesso'];$perm=$this->d->map[7][2]??[];
   $this->rows=[['consentito_massimo'=>$perm[$name]??0,'righe_trovate'=>array_key_exists($name,$perm)?1:0]];
  }
  elseif(str_starts_with($q,'INSERT INTO aut_ruoli_permessi')){
   if(!$this->d->tx)throw new RuntimeException('Write outside transaction');
   $name=$p['permesso']??'menu';
   if(!empty($this->d->case['fail']) && $name==='write')throw new PDOException('Fixture save failure');
   $this->d->map[$p['id_ruolo']][$p['id_risorsa']][$name]=$p['consentito'];
   $this->d->writes[]=$p+['permesso'=>$name];
  }
  else throw new RuntimeException('Unhandled SQL '.$q);
  return true;
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed{return array_shift($this->rows)??false;}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return $this->rows;}
}
$pdo=new PermPDO($case);function db():PDO{global $pdo;return $pdo;}
eval('?>'.sourcePhp($root.'/includes/autorizzazioni.php'));
eval('?>'.sourcePhp($root.'/includes/auth.php'));
$_SESSION=['id_utente'=>9,'utente_id'=>9,'username'=>'admin','ruoli'=>['admin_portale']];
$_SERVER=['REQUEST_METHOD'=>($case['post']??false)?'POST':'GET','PHP_SELF'=>'permessi_ruoli.php'];
$_GET=['id_ruolo'=>7];$_POST=[];
if(!empty($case['post']))$_POST=['id_ruolo'=>7,'salva_permessi'=>1,'permesso_risorsa_2'=>$case['level'],'permesso_risorsa_3'=>'none','menu_risorsa_1'=>1]+(!empty($case['menu'])?['menu_risorsa_2'=>1]:[]);
$src=sourcePhp($root.'/permessi_ruoli.php');
ob_start();
register_shutdown_function(function()use($pdo){echo json_encode(['html'=>ob_get_clean(),'map'=>$pdo->map,'writes'=>$pdo->writes,'committed'=>$pdo->committed,'rolledBack'=>$pdo->rolledBack,'fatal'=>error_get_last()],JSON_THROW_ON_ERROR);});
if(($case['mode']??'')==='levels'){
 $a=strpos($src,'function dettaglioPermessiRuolo(');$b=strpos($src,'function menuCorrenteRuolo(',$a);eval(substr($src,$a,$b-$a));
 $total=0;
 foreach([true,false]as $pagina)foreach([null,0,1]as $r)foreach([null,0,1]as $w)foreach([null,0,1]as $v)foreach([null,0,1]as $e){
  $bits=[];foreach(['read'=>$r,'write'=>$w,'view'=>$v,'edit'=>$e]as $key=>$value)if($value!==null)$bits[$key]=$value;
  $pdo->map[7][2]=$bits;
  $level='none';foreach(['edit','write','create','delete','execute']as $key)if(($bits[$key]??0)===1)$level='write';
  if($level==='none'&&(($bits['read']??0)===1||($bits['view']??0)===1))$level='read';
  $_SESSION=['id_utente'=>1,'utente_id'=>1,'username'=>'demo','ruoli'=>['operativo_ordini'],'permessi'=>['assenze'=>$level]];
  $resource=$pagina?'pagina.assenze':'azione.hr.riservata';
  $read=haPermesso($resource,'read');$write=haPermesso($resource,'write');
  $expected=!$read?'none':($write?'write':'read');
  $actual=dettaglioPermessiRuolo([7=>[2=>$bits]],7,2,$pagina)['livello'];
  if($expected!==$actual)throw new RuntimeException('Runtime mismatch '.json_encode([$bits,$pagina,$expected,$actual]));$total++;
 }
 echo 'Comparazione runtime PASS: '.$total.' combinazioni.';
}else eval('?>'.$src);
