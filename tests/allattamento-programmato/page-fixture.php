<?php
declare(strict_types=1);
// Orologio di prova fisso: lo scenario richiesto resta ripetibile anche dopo marzo 2027.
function hrAllattOggi(): string { return '2026-10-13'; }
$case=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
require dirname(__DIR__,2).'/includes/hr_allattamento_programmato.php';
require __DIR__.'/fixture.php';
function utenteAdminGlobale(): bool {return ($_SESSION['username']??'')==='admin';}
function haPermessoScrittura(string $r): bool {return empty($_SESSION['deny']);}
function richiediPermessoLettura(string $r): void {
    if (!empty($_SESSION['impersonazione_attiva']) && $_SERVER['REQUEST_METHOD']==='POST') {http_response_code(403);exit('Sola lettura');}
}
function layoutHeader(string $s): void {echo '<!doctype html><html><head><meta charset="UTF-8"></head><body>';}
function layoutFooter(): void {echo '</body></html>';}
function hrCreaNotificaWeb(...$args): void {global $web;$web[]=$args;}
function hrCreaNotificaEmailPerUtenti(...$args): array {global $email;$email[]=$args;return ['saltate'=>0,'errori'=>[]];}
function hrRiepilogoAssenzeInviaAggiornamentoSeNecessario(...$args): void {global $riepiloghi;$riepiloghi[]=$args;}
$d=allattFixture();$web=[];$email=[];$riepiloghi=[];
function db(): PDO {global $d;return $d;}
$_SESSION=['id_utente'=>9,'ruoli'=>['hr_responsabile_personale'],'hr_regole_csrf'=>'csrf-demo'];
if (($case['role']??'')==='user')$_SESSION['ruoli']=[];
if (($case['role']??'')==='admin'){$_SESSION['ruoli']=[];$_SESSION['username']='admin';}
$_SESSION['deny']=$case['deny']??false;
$_SESSION['impersonazione_attiva']=$case['impersonate']??false;
$_SERVER['REQUEST_METHOD']=$case['method']??'GET';
$_GET=['id_utente'=>1];$_POST=['csrf_token'=>'csrf-demo']+($case['post']??[]);
if (!empty($case['csrf_bad']))$_POST['csrf_token']='errato';
if (!empty($case['seed'])) {
    $_SESSION['deny']=false;
    $c=hrAllattConfig(allattInput());$p=hrAllattPrepara($d,$c);$d->beginTransaction();hrRegoleBloccaScrittura($d);hrAllattSalva($d,$p,9);$d->commit();
    $_GET['programma']=1;$_SESSION['deny']=$case['deny']??false;
}
if (!empty($case['new_closure']))$d->exec("INSERT INTO hr_chiusure_aziendali VALUES(1,'Chiusura test','2026-10-20','2026-10-20',1)");
if (!empty($case['confirm'])) {
    $input=allattInput();$c=hrAllattConfig($input);$p=hrAllattPrepara($d,$c);
    $_SESSION['hr_allatt_anteprime']['token-demo']=['input'=>$input,'operatore'=>9,'scadenza'=>time()+1000,'impronta'=>hrAllattImpronta($p)];
    $_POST=['azione'=>'conferma','token_anteprima'=>'token-demo','csrf_token'=>'csrf-demo'];
    if (!empty($case['csrf_bad']))$_POST['csrf_token']='errato';
    if (!empty($case['expired']))$_SESSION['hr_allatt_anteprime']['token-demo']['scadenza']=time()-1;
    if (!empty($case['consumed']))unset($_SESSION['hr_allatt_anteprime']['token-demo']);
    if (!empty($case['changed']))$d->exec("INSERT INTO hr_chiusure_aziendali VALUES(2,'Cambiata','2026-10-21','2026-10-21',1)");
}
ob_start();
register_shutdown_function(static function()use($d,&$errore,&$web,&$email,&$riepiloghi){
    $html=ob_get_clean();$e=error_get_last();
    echo json_encode(['html'=>$html,'error'=>$errore??'','fatal'=>$e,'status'=>http_response_code(),
        'programmi'=>(int)$d->query('SELECT COUNT(*) FROM hr_allattamento_programmi')->fetchColumn(),
        'richieste'=>(int)$d->query('SELECT COUNT(*) FROM hr_richieste')->fetchColumn(),
        'web'=>count($web),'email'=>count($email),'riepiloghi'=>count($riepiloghi),
        'notifica'=>$web[0][3]??'','pending'=>count($_SESSION['hr_allatt_anteprime']??[])],JSON_THROW_ON_ERROR);
});
$source=file_get_contents(dirname(__DIR__,2).'/allattamento_programmato.php');
$source=preg_replace('/^require_once .*;\s*$/m','',$source);
$source=str_replace('declare(strict_types=1);','',$source);
eval('?>'.$source);
