<?php
declare(strict_types=1);
function check(bool $ok, string $msg): void { if (!$ok) throw new RuntimeException($msg); }
class FixturePDO extends PDO {
    public array $logs = [];
    public array $queries = [];
    public string $state = 'APPROVATA';
    public bool $missingEmail = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->queries[]=$query; return new FixtureStatement($this,$query); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $s=$this->prepare($query);$s->execute();return $s; }
    public function lastInsertId(?string $name = null): string|false { return '123'; }
}
class FixtureStatement extends PDOStatement {
    private array $rows=[];
    public function __construct(private FixturePDO $db,private string $sql) {}
    public function execute(?array $params = null): bool {
        $p=$params??[];$q=$this->sql;$this->rows=[];
        if(str_contains($q,'INSERT INTO hr_riepilogo_assenze_invi')) $this->db->logs[]=$p;
        elseif(str_contains($q,'SELECT COUNT(*)') && str_contains($q,'hr_riepilogo_assenze_invi')) {
            $n=0;foreach($this->db->logs as $l) if($l['data_riepilogo']===$p['data_riepilogo'] && $l['tipo_invio']===$p['tipo_invio'] && $l['id_utente']===$p['id_utente'] && $l['livello']===$p['livello'] && $l['esito']==='INVIATA' && (($l['id_richiesta']??null)===($p['id_richiesta']??null))) $n++;$this->rows=[[$n]];
        }
        elseif(str_contains($q,'FROM hr_configurazioni') && str_contains($q,'codice IN')) {
            foreach(['HR_NOTIFICA_EMAIL_ATTIVA'=>'1','HR_EMAIL_FROM'=>'no-reply@example.invalid','HR_EMAIL_FROM_NAME'=>'Ravioli S.p.A.','HR_URL_PORTALE'=>'https://portal.example.invalid'] as $k=>$v)$this->rows[]=['codice'=>$k,'valore'=>$v];
        }
        elseif(str_contains($q,'HR_RIEPILOGO_ASSENZE_ATTIVO') || str_contains($q,'HR_RIEPILOGO_ASSENZE_BCC_ADMIN')) $this->rows=[['1']];
        elseif(str_contains($q,'FROM hr_riepilogo_assenze_destinatari')) $this->rows=[['id_utente'=>3,'livello_dettaglio'=>'HR'],['id_utente'=>4,'livello_dettaglio'=>'BASE'],['id_utente'=>5,'livello_dettaglio'=>'BASE']];
        elseif(str_contains($q,"LOWER(username) = 'admin'")) $this->rows=[[99]];
        elseif(str_contains($q,'SELECT ru.valore')) $this->rows=$this->db->missingEmail?[]:[['person'.$p['id_utente'].'@example.invalid']];
        elseif(str_contains($q,'AS nominativo')) $this->rows=[['Demo Destinatario '.$p['id_utente']]];
        elseif(str_contains($q,'AS data_da_min')) $this->rows=[['data_da_min'=>'2026-10-01','data_a_max'=>'2026-10-01']];
        elseif(str_contains($q,'AS persona')) $this->rows=[['persona'=>'Demo Collaboratore','tipologia'=>'Assenza/permesso','stato'=>'In attesa','stato_codice'=>'IN_ATTESA','data_da'=>'2026-10-01','data_a'=>'2026-10-01','ha_ore'=>1,'ora_da'=>'11:00','ora_a'=>'12:00']];
        elseif(str_contains($q,'AS richiedente')) $this->rows=[['id_richiesta'=>101,'codice_richiesta'=>'HR-DEMO-101','richiedente'=>'Demo Richiedente','id_utente_richiedente'=>1,'tipologia'=>'Permesso','tipologia_codice'=>'PERMESSO','oggetto'=>'Visita fornitore - Gias & C.','note_richiedente'=>'Nota con accenti: è già più chiara. <td>non è HTML</td>','stato_codice'=>$this->db->state,'stato'=>match($this->db->state){'IN_ATTESA'=>'In attesa','ANNULLATA'=>'Annullata','RIFIUTATA'=>'Rifiutata',default=>'Approvata'}]];
        elseif(str_contains($q,'FROM hr_richieste_periodi')) $this->rows=[['tipo_periodo'=>'ORE','data_da'=>'2026-10-01','data_a'=>'2026-10-01','ora_da'=>'09:00:00','ora_a'=>'12:30:00']];
        elseif(str_contains($q,'stato_presenza_breve')) {
            $this->rows=demoRows();
            if(str_contains($q,"IN ('APPROVATA', 'IN_ATTESA')"))$this->rows[]=['nome'=>'Demo','cognome'=>'Pendente','tipologia'=>'Visita medica','descrizione_calendario'=>'Visita medica','mostra_dettaglio_hr'=>1,'stato_presenza_breve'=>'Assente','tipo_periodo'=>'GIORNI','data_da'=>'2026-10-01','data_a'=>'2026-10-01','oggetto'=>'Oggetto visibile a HR'];
        }
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT,int $cursorOrientation = PDO::FETCH_ORI_NEXT,int $cursorOffset = 0): mixed { return array_shift($this->rows)??false; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT,mixed ...$args): array { return $this->rows; }
    public function fetchColumn(int $column = 0): mixed { $r=$this->rows[0]??[];return array_values($r)[$column]??false; }
}
function demoRows(): array {
    $rows=[];
    foreach(['Alfa','Beta','Gamma','Delta','Epsilon','Zeta','Eta'] as $i=>$c){$rows[]=['nome'=>'Demo','cognome'=>$c,'username'=>'Demo'.$c,'tipologia'=>$i===4?'Visita fornitore':'Permesso','descrizione_calendario'=>$i===4?'Visita fornitore':'Permesso','mostra_dettaglio_hr'=>1,'stato_presenza_breve'=>'Assente','tipo_periodo'=>in_array($i,[3,5],true)?'GIORNI':'ORE','data_da'=>'2026-10-01','data_a'=>$i===5?'2026-10-02':'2026-10-01','ora_da'=>'09:00:00','ora_a'=>'12:30:00','oggetto'=>$i===4?'Visita fornitore - Gias':($i===1?'GIAS':'')];}
    return $rows;
}
require __DIR__.'/runtime/includes/hr_email.php';
require __DIR__.'/runtime/includes/hr_riepilogo_assenze.php';
require __DIR__.'/runtime/includes/hr_recapiti.php';
$pdo=new FixturePDO();
$cases=[
 ['ricevuta','IN_ATTESA','RICHIESTA_ASSENZA_REGISTRATA','Richiesta registrata','La tua richiesta è stata registrata correttamente ed è in attesa di approvazione.',1],
 ['da_approvare','IN_ATTESA','RICHIESTA_ASSENZA_DA_APPROVARE','Richiesta da approvare','È stata inserita una nuova richiesta da un collaboratore.',2],
 ['approvata','APPROVATA','RICHIESTA_ASSENZA_APPROVATA_EMAIL','Richiesta approvata','La tua richiesta è stata approvata.',1],
 ['automatica','APPROVATA','RICHIESTA_ASSENZA_REGISTRATA','Richiesta registrata e approvata','È stata registrata per te una richiesta già approvata.',1],
 ['informativa','APPROVATA','RICHIESTA_ASSENZA_INFORMATIVA_RESPONSABILE','Informazione: richiesta registrata','La comunicazione è solo informativa: non è richiesta alcuna approvazione.',2],
 ['rifiutata','RIFIUTATA','RICHIESTA_ASSENZA_RIFIUTATA_EMAIL','Richiesta rifiutata','La tua richiesta è stata rifiutata. Motivazione: sovrapposizione attività.',1],
 ['annullata','ANNULLATA','RICHIESTA_ASSENZA_ANNULLATA','Richiesta annullata','Una richiesta di assenza o permesso è stata annullata.',1],
];
@mkdir(__DIR__.'/output');
foreach($cases as [$name,$state,$event,$title,$message,$dest]) {
 $pdo->state=$state;
 $html=hrEmailHtml($pdo,$title,$message,'/assenze.php',101,$event,$dest);
 file_put_contents(__DIR__.'/output/'.$name.'.html',$html);
 check(hrInviaEmail($pdo,'person'.$dest.'@example.invalid',$title,$message,'/assenze.php',101,$event,$dest)['inviata'],$name);
}
foreach(['BASE','HR'] as $level)file_put_contents(__DIR__.'/output/riepilogo_'.strtolower($level).'.html',hrRiepilogoAssenzeHtml('2026-10-01',demoRows(),$level));
file_put_contents(__DIR__.'/output/riepilogo_vuoto.html',hrRiepilogoAssenzeHtml('2026-10-01',[],'BASE'));
check(hrRiepilogoAssenzeInvia($pdo,'2026-10-01')['inviate']===3,'automatic digest');
check(hrRiepilogoAssenzeInvia($pdo,'2026-10-01')['saltate']===3,'dedup digest');
check(hrRiepilogoAssenzeInvia($pdo,'2026-10-01','AGGIORNAMENTO',101,'HR')['inviate']===1,'HR update only');
check(hrRiepilogoAssenzeInviaTestAdmin($pdo,'2026-10-01')['inviate']===2,'admin test');
check(hrRiepilogoAssenzeInviaTestDestinatari($pdo,'2026-10-01')['inviate']===3,'recipient test');
check(hrRecapitiInviaVerifica($pdo,1,'personal@example.invalid',str_repeat('a',64))['inviata'],'verification');
$protected=['nome'=>'Demo','cognome'=>'Privato','tipologia'=>'Motivo riservato','descrizione_calendario'=>'Motivo riservato','mostra_dettaglio_hr'=>0,'stato_presenza_breve'=>'Assente'];
check(hrRiepilogoAssenzeMotivo($protected,'HR')==='Assente','masked reason');
check(!hrEmailInviaHtml(['attiva'=>false,'from_email'=>'sender@example.invalid','from_name'=>'Demo'],'to@example.invalid','Subject','body'),'disabled');
check(!hrInviaEmail($pdo,'not-valid','Title','Message')['inviata'],'invalid recipient');
$long=str_repeat('È già più chiaro — 中文 😀 ',20);
file_put_contents(__DIR__.'/output/header_encoded.txt',hrEmailEncodeHeader($long));
file_put_contents(__DIR__.'/output/header_original.txt',$long);
$hostile=hrRiepilogoAssenzeHtml('2026-10-01',[['nome'=>'<script>','cognome'=>'& "Demo"','oggetto'=>'<tr> &ndash; 😀','tipo_periodo'=>'GIORNI']],'BASE');
check(!str_contains($hostile,'<script>'),'HTML escaped');
file_put_contents(__DIR__.'/output/escaped.html',$hostile);
$pdo->missingEmail=true;check(hrRiepilogoAssenzeInviaTestDestinatari($pdo,'2026-10-01')['errori']===3,'missing verified email');
file_put_contents(__DIR__.'/output/queries.json',json_encode($pdo->queries));
echo "Workflow, digest automatic/manual, BCC, dedup, verification, disabled/invalid/missing-email: PASS\n";
