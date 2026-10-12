<?php
require dirname(__DIR__,2).'/includes/hr_calendario.php';
$row=['codice_tipologia'=>'CONGEDO_STRAORDINARIO_DISABILI','id_utente_richiedente'=>2,'descrizione_calendario'=>'Congedo Straordinario Disabili','tipologia'=>'Congedo Straordinario Disabili','stato_presenza_breve'=>'Assente','stato_presenza'=>'Assente','mostra_dettaglio_hr'=>1,'mostra_dettaglio_responsabili'=>1,'mostra_dettaglio_colleghi'=>1,'codice_stato_richiesta'=>'APPROVATA','stato_richiesta'=>'Approvata','tipo_periodo'=>'GIORNI','ora_da'=>'','ora_a'=>'','oggetto'=>'MOTIVO RISERVATO','nome'=>'Persona','cognome'=>'Prova','username'=>'Demo','codice_richiesta'=>'HR-DEMO'];
$src=file_get_contents(dirname(__DIR__,2).'/calendario_personale_ics.php');$a=strpos($src,'function icsPresentazioneEvento(');$b=strpos($src,'$pdo = db();',$a);eval(substr($src,$a,$b-$a));
$n=0;
foreach([1,2] as $owner)foreach([false,true] as $config)foreach([false,true] as $types)foreach([false,true] as $hr){
 $shown=hrMostraDettaglioCalendario($row,[2=>['gerarchia'=>true,'gruppo'=>true]],$owner,$config,$types,$hr);
 if($shown!==$hr)throw new Exception('Privacy mismatch');
 $p=icsPresentazioneEvento($row,[2=>['gerarchia'=>true,'gruppo'=>true]],$owner,$config,$types,$hr);
 if(!$hr&&(str_contains(json_encode($p),'CONGEDO')||str_contains(json_encode($p),'Congedo')||str_contains(json_encode($p),'MOTIVO')))throw new Exception('ICS privacy leak');
 if(!$hr&&!str_contains($p['titolo'],'Assente'))throw new Exception('Assente required');$n++;
}
foreach(['hr_responsabile_personale','admin_portale','dipendente','direzione_visibilita_hr'] as $role){
 $p=hrCalendarioValutaPermessi('Demo',[['codice_ruolo'=>$role,'codice_risorsa'=>'pagina.calendario_assenze','permesso'=>'read','consentito'=>1]]);
 if($p['hr']!==in_array($role,['hr_responsabile_personale','admin_portale'],true))throw new Exception('Role mismatch');$n++;
}
echo 'Privacy calendario/ICS PASS: '.$n.' combinazioni, inclusi proprietario e permessi estesi.';

$src=file_get_contents(dirname(__DIR__,2).'/includes/hr_email.php');$a=strpos($src,'function hrEmailFiltraCongedo(');$b=strpos($src,"if (!function_exists('hrEmailHtml'))",$a);eval(substr($src,$a,$b-$a));
$req=['tipologia_codice'=>'CONGEDO_STRAORDINARIO_DISABILI','tipologia'=>'Congedo Straordinario Disabili','oggetto'=>'OGGETTO RISERVATO','note_richiedente'=>'NOTE RISERVATE'];
if(hrEmailFiltraCongedo($req,false)!==['tipologia_codice'=>'ASSENTE','tipologia'=>'Assente','oggetto'=>'','note_richiedente'=>''])throw new Exception('Email privacy');
if(hrEmailFiltraCongedo($req,true)!==$req)throw new Exception('HR email detail');
echo "\nPrivacy email PASS: non HR vede Assente, HR mantiene dettaglio.";
