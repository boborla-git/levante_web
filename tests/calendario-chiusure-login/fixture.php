<?php
declare(strict_types=1);
$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
require $root . '/includes/hr_calendario.php';
$_SESSION = ['id_utente'=>1,'utente_id'=>1];
$_GET = ['vista'=>$case['view'] ?? 'mese','data'=>$case['date'] ?? '2099-08-10'];
if (!empty($case['all'])) $_GET['mostra'] = 'tutti';
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
function richiediPermessoLettura(...$x): void {}
function renderHrAlert(string $msg, string $type): void { if ($msg) echo '<div class="fixture-error">'.htmlspecialchars($msg).'</div>'; }
function layoutHeader(...$x): void {}
function layoutFooter(...$x): void {}
function mb_strtoupper(string $v, string $encoding='UTF-8'): string { return strtoupper($v); }
function mb_substr(string $v,int $start,?int $length=null,string $encoding='UTF-8'): string { return substr($v,$start,$length); }
class CalPDO extends PDO {
    public array $queries=[];
    public function __construct(public array $case) {}
    public function prepare(string $s,array $options=[]): PDOStatement|false { return new CalStmt($this,$s); }
    public function query(string $s,?int $mode=null,mixed ...$args): PDOStatement|false { $q=$this->prepare($s);$q->execute();return $q; }
}
class CalStmt extends PDOStatement {
    public array $rows=[];
    public function __construct(public CalPDO $db,public string $sql) {}
    public function execute(?array $params=null): bool {
        $p=$params ?? []; $c=$this->db->case; $s=preg_replace('/\s+/',' ',trim($this->sql));
        $this->db->queries[]=['sql'=>$s,'params'=>$p]; $this->rows=[];
        $users=[['id_utente'=>1,'nome'=>'Mario','cognome'=>'Rossi','username'=>'MRossi'],['id_utente'=>2,'nome'=>'Luca','cognome'=>'Verdi','username'=>'LVerdi'],['id_utente'=>3,'nome'=>'Anna','cognome'=>'Bianchi','username'=>'ABianchi']];
        if (str_starts_with($s,'SELECT username FROM aut_utenti')) $this->rows=[[$c['admin'] ?? false ? 'admin' : 'MRossi']];
        elseif(str_starts_with($s,'SELECT ar.codice_ruolo')) $this->rows=[['codice_ruolo'=>'dipendente','codice_risorsa'=>'pagina.calendario_assenze','permesso'=>'read','consentito'=>empty($c['denied'])?1:0]];
        elseif(str_contains($s,'FROM hr_relazioni_organizzative')) $this->rows=[['id_utente'=>2]];
        elseif(str_contains($s,'FROM hr_gruppi_utenti')) $this->rows=[];
        elseif(str_starts_with($s,'SELECT id_utente, nome, cognome, username')) {
            $this->rows=array_values(array_filter($users,fn($u)=>!str_contains($s,' IN (')||in_array($u['id_utente'],$p,true)));
        }
        elseif(str_contains($s,'FROM hr_chiusure_aziendali')) {
            foreach($c['closures'] ?? [] as $cl) {
                if (($cl['attivo'] ?? 1)!==1 || $cl['data_da']>$p['fine'] || $cl['data_a']<$p['inizio']) continue;
                unset($cl['attivo']);$this->rows[]=$cl;
            }
        }
        elseif(str_starts_with($s,'SELECT r.id_richiesta, r.codice_richiesta')) {
            $ids=array_slice($p,0,count($p)-5);$fine=$p[count($p)-5];$inizio=$p[count($p)-4];
            foreach($c['events'] ?? [] as $e) {
                $e+=['id_utente_richiedente'=>2,'data_da'=>'2099-08-10','data_a'=>'2099-08-10','tipo_periodo'=>'GIORNI','ora_da'=>'','ora_a'=>'','id_richiesta'=>10,'codice_tipologia'=>'FERIE','codice_stato_richiesta'=>'APPROVATA','stato_richiesta'=>'Approvata','tipologia'=>'Ferie','descrizione_calendario'=>'Ferie','stato_presenza_breve'=>'Assente','stato_presenza'=>'Assente','mostra_dettaglio_responsabili'=>0,'mostra_dettaglio_hr'=>1,'mostra_dettaglio_colleghi'=>0,'oggetto'=>'MOTIVO_RISERVATO'];
                if(!in_array($e['id_utente_richiedente'],$ids,true)||$e['data_da']>$fine||$e['data_a']<$inizio) continue;
                if($e['codice_stato_richiesta']==='IN_ATTESA' && !$c['admin'] && $e['id_utente_richiedente']!==1) continue;
                $this->rows[]=$e;
            }
        }
        else throw new RuntimeException('Query non prevista: '.$s);
        return true;
    }
    public function fetchColumn(int $column=0): mixed { return $this->rows?array_values($this->rows[0])[$column]:false; }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0): mixed { return array_shift($this->rows)??false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return $mode===PDO::FETCH_COLUMN?array_map(fn($r)=>array_values($r)[0],$this->rows):$this->rows; }
}
$pdo=new CalPDO($case);
function db(): PDO { global $pdo;return $pdo; }
ob_start();
register_shutdown_function(function() use($pdo) {echo json_encode(['html'=>ob_get_clean(),'queries'=>$pdo->queries,'fatal'=>error_get_last(),'status'=>http_response_code()],JSON_THROW_ON_ERROR);});
$source=file_get_contents($root.'/calendario_assenze.php');
$source=preg_replace('/^require_once .*;\s*$/m','',$source);
$source=str_replace('declare(strict_types=1);','',$source);
eval('?>'.$source);
