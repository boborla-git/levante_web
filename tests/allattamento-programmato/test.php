<?php
declare(strict_types=1);
// Orologio di prova fisso: lo scenario richiesto resta ripetibile anche dopo marzo 2027.
function hrAllattOggi(): string { return '2026-10-13'; }
require dirname(__DIR__,2).'/includes/hr_allattamento_programmato.php';
require __DIR__.'/fixture.php';
function utenteAdminGlobale(): bool { return ($_SESSION['username']??'')==='admin'; }
function haPermessoScrittura(string $r): bool { return !in_array($r,$_SESSION['deny']??[],true); }
$_SESSION=['ruoli'=>['hr_responsabile_personale']];
$checks=0;
function ok(bool $b,string $label): void { global $checks;if(!$b)throw new RuntimeException($label);$checks++; }
function rifiuta(callable $f,string $test): void { try{$f();}catch(Throwable $e){ok(true,$test);return;}throw new RuntimeException('Non rifiutato: '.$test); }
function config(array $extra=[]): array { return hrAllattConfig(allattInput($extra),'2026-10-13'); }
$c=config(); $p=hrAllattPiano($c,[],[]);
ok(count($p['inserire'])===208,'104 giorni x 2 fasce');
ok(end($p['inserire'])['data_da']==='2027-03-05','ultimo venerdì');
ok(!$p['conflitti'],'nessun conflitto');
foreach($p['inserire'] as $r)ok((int)(new DateTimeImmutable($r['data_da']))->format('N')<=5 && $r['minuti']===60,'giorno lavorativo e un’ora');
$chiusure=[['data_da'=>'2026-12-24','data_a'=>'2027-01-06','descrizione'=>'Chiusura Natale']];
$p=hrAllattPiano($c,$chiusure,[]);ok(count($p['chiusure'])===10 && count($p['inserire'])===188,'chiusura inclusiva, solo lun-ven');
foreach(['2027-01-06','2026-12-24'] as $g)ok(!in_array($g,array_column($p['inserire'],'data_da')),'estremi chiusura');
foreach([
 ['giorni'=>[]],['giorni'=>[0]],['giorni'=>[8]],['data_a'=>'2026-10-12'],['data_da'=>'2026-02-30'],
 ['ora_da'=>['08:00'],'ora_a'=>['10:15']],['ora_da'=>['07:45'],'ora_a'=>['08:45']],
 ['ora_da'=>['08:10'],'ora_a'=>['09:00']],['ora_da'=>['08:00','08:30'],'ora_a'=>['09:00','09:30']],
 ['ora_da'=>['16:00'],'ora_a'=>['16:00']],['ora_da'=>['16:00'],'ora_a'=>['17:15']],
 ['data_da'=>'2026-10-12'],['data_a'=>'2029-01-01'],['azione_programma'=>'hacker'],
 ['azione_programma'=>'giorno','id_programma'=>1],['azione_programma'=>'modifica','id_programma'=>0],
] as $bad)rifiuta(fn()=>config($bad),'configurazione non valida');
ok(config(['ora_da'=>['08:00','09:00'],'ora_a'=>['09:00','10:00']])['fasce'][1]['ora_da']==='09:00','fasce adiacenti');
$_SESSION=['ruoli'=>[]];rifiuta(fn()=>hrAllattRichiediOperatore(),'utente escluso');
$_SESSION=['ruoli'=>['operativo_ordini']];rifiuta(fn()=>hrAllattRichiediOperatore(),'ruolo non HR escluso');
$_SESSION=['ruoli'=>[],'username'=>'admin'];hrAllattRichiediOperatore();ok(true,'admin abilitato');
$_SESSION=['ruoli'=>['hr_responsabile_personale'],'deny'=>['assenze']];rifiuta(fn()=>hrAllattRichiediOperatore(),'HR senza scrittura assenze');
$_SESSION=['ruoli'=>['hr_responsabile_personale']];
$d=allattFixture();
$d->exec("INSERT INTO hr_chiusure_aziendali VALUES(1,'Chiusura Natale','2026-12-24','2027-01-06',1)");
$p=hrAllattPrepara($d,$c);$fingerprint=hrAllattImpronta($p);
ok(count($p['inserire'])===188,'anteprima DB');
$d->beginTransaction();hrRegoleBloccaScrittura($d);$ris=hrAllattSalva($d,$p,9);$d->commit();
ok($ris['inserite']===188,'inserimento concreto');$programma=$ris['id_programma'];
ok((int)$d->query('SELECT COUNT(*) FROM hr_richieste')->fetchColumn()===188,'richieste ordinarie');
ok((int)$d->query('SELECT COUNT(*) FROM hr_allattamento_programma_richieste')->fetchColumn()===188,'legami');
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste WHERE id_stato_richiesta=1 AND origine='web'")->fetchColumn()===188,'tutte approvate');
ok((int)$d->query('SELECT COUNT(*) FROM hr_richieste_storico')->fetchColumn()===188,'storico operatore');
ok((int)$d->query('SELECT COUNT(*) FROM hr_allattamento_programmi_storico')->fetchColumn()===1,'audit serie');
ok($d->locks===1,'mutex condiviso');
// Calendario/ICS e riepiloghi leggono i periodi normali: nessuna tabella duplicata di eventi.
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta
 WHERE r.id_stato_richiesta=1 AND p.data_da='2026-10-13'")->fetchColumn()===2,'due fasce calendario oggi');
$doppio=hrAllattPrepara($d,$c);ok(!$doppio['inserire']&&count($doppio['duplicati'])===188,'nessun doppio inserimento');
ok(hrAllattImpronta($doppio)!==$fingerprint,'vecchia anteprima invalidata');
rifiuta(fn()=>hrAllattSalva($d,$doppio,9),'non scrive senza transazione');
$d->beginTransaction();hrRegoleBloccaScrittura($d);rifiuta(fn()=>hrAllattSalva($d,$doppio,9),'nessuna scrittura se tutto duplicato');$d->rollBack();
$chiusuraPrima=hrAllattPrepara($d,$c);$d->exec("INSERT INTO hr_chiusure_aziendali VALUES(2,'Nuova chiusura','2026-10-15','2026-10-15',1)");
ok(hrAllattImpronta(hrAllattPrepara($d,$c))!==hrAllattImpronta($chiusuraPrima),'chiusura dopo anteprima');
$cleanup=config(['azione_programma'=>'chiusure','id_programma'=>$programma]);
$p=hrAllattPrepara($d,$cleanup);ok(count($p['annullare'])===2,'nuova chiusura seleziona due fasce');
$d->beginTransaction();hrRegoleBloccaScrittura($d);$ris=hrAllattSalva($d,$p,9);$d->commit();
ok($ris['annullate']===2,'annulla chiusure insieme');
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da='2026-10-15'")->fetchColumn()===0,'chiusura assente dal calendario ordinario');
// Richiesta manuale indipendente non viene mai assorbita o annullata dalla serie.
$indipendente=allattInserisciManuale($d,'2026-11-04','12:00:00','13:00:00',2);
$mod=config(['azione_programma'=>'modifica','id_programma'=>$programma,'data_da'=>'2026-11-02','ora_da'=>['08:00','15:00'],'ora_a'=>['09:00','16:00']]);
$p=hrAllattPrepara($d,$mod);ok(!$p['conflitti'],'modifica orari futuri');
ok(!in_array($indipendente,array_column($p['annullare'],'id_richiesta')),'non tocca richiesta indipendente');
$past=(int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da<'2026-11-02'")->fetchColumn();
$d->beginTransaction();hrRegoleBloccaScrittura($d);$ris=hrAllattSalva($d,$p,9);$d->commit();
ok($ris['inserite']>0&&$ris['annullate']>0,'sostituzione futura');
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da<'2026-11-02'")->fetchColumn()===$past,'precedente preservato');
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da='2026-11-02' AND p.ora_da='15:00'")->fetchColumn()===1,'nuova fascia');
$riga=$d->query("SELECT r.id_richiesta FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da='2026-11-02' AND p.ora_da='15:00'")->fetchColumn();
$singola=config(['azione_programma'=>'giorno','id_programma'=>$programma,'id_richiesta'=>$riga,'data_da'=>'2026-11-02','data_a'=>'2026-11-02']);
$p=hrAllattPrepara($d,$singola);ok(count($p['annullare'])===1,'singola fascia');
$d->beginTransaction();hrRegoleBloccaScrittura($d);hrAllattSalva($d,$p,9);$d->commit();
$giorno=config(['azione_programma'=>'giorno','id_programma'=>$programma,'data_da'=>'2026-11-03','data_a'=>'2026-11-03']);
ok(count(hrAllattPrepara($d,$giorno)['annullare'])===2,'giornata intera');
$stop=config(['azione_programma'=>'annulla','id_programma'=>$programma,'data_da'=>'2026-12-01']);
$p=hrAllattPrepara($d,$stop);$d->beginTransaction();hrRegoleBloccaScrittura($d);hrAllattSalva($d,$p,9);$d->commit();
ok((int)$d->query("SELECT COUNT(*) FROM hr_richieste r JOIN hr_richieste_periodi p ON p.id_richiesta=r.id_richiesta WHERE r.id_stato_richiesta=1 AND p.data_da>='2026-12-01'")->fetchColumn()===0,'interruzione futuro');
rifiuta(fn()=>hrAllattPrepara($d,config(['azione_programma'=>'modifica','id_programma'=>$programma,'id_utente'=>2])),'programma altro utente');
$d->exec("UPDATE hr_benefici_utenti SET attivo=0 WHERE id_utente=1");
rifiuta(fn()=>hrAllattPrepara($d,$c),'beneficio revocato');
ok(count(hrAllattPrepara($d,$giorno)['annullare'])===2,'può annullare dopo revoca diritto');
$d->exec('UPDATE aut_utenti SET attivo=0 WHERE id_utente=1');
rifiuta(fn()=>hrAllattPrepara($d,$c),'dipendente disattivato non può avere nuovi inserimenti');
ok(count(hrAllattPrepara($d,$giorno)['annullare'])===2,'annullamento resta disponibile per dipendente disattivato');
// Conflitti, limite giornaliero (anche fasce non sovrapposte), stati e rollback reale.
foreach([1,2,4,3,5] as $stato){
 $x=allattFixture();allattInserisciManuale($x,'2026-10-14','08:30:00','09:30:00',2,$stato);
 $p=hrAllattPrepara($x,$c);ok((bool)$p['conflitti']===in_array($stato,[1,2,4]),'stati sovrapposizione');
}
$x=allattFixture();allattInserisciManuale($x,'2026-10-14','11:00:00','12:00:00');
ok((bool)hrAllattPrepara($x,$c)['conflitti'],'tre ore non sovrapposte rifiutate');
$x=allattFixture();allattInserisciManuale($x,'2026-10-14','09:00:00','10:00:00',2);
ok(!hrAllattPrepara($x,$c)['conflitti'],'confine 09:00 non sovrapposto');
$x=allattFixture();$p=hrAllattPrepara($x,$c);$x->exec("UPDATE hr_benefici_utenti SET data_fine='2026-10-14' WHERE id_utente=1");
$x->beginTransaction();hrRegoleBloccaScrittura($x);rifiuta(fn()=>hrAllattSalva($x,$p,9),'regole comuni ricontrollate al salvataggio');$x->rollBack();
ok((int)$x->query('SELECT COUNT(*) FROM hr_richieste')->fetchColumn()===0,'rollback delle richieste già inserite');
ok((int)$x->query('SELECT COUNT(*) FROM hr_allattamento_programmi')->fetchColumn()===0,'rollback della serie');
$x=allattFixture();$x->exec("UPDATE hr_benefici_utenti SET data_fine=NULL WHERE id_utente=1");rifiuta(fn()=>hrAllattPrepara($x,$c),'beneficio senza termine');
// Notifiche: stesso helper di produzione, trasporto sostituito; personale solo per programma/dipendente.
function hrEmailConfig(PDO $d): array {return ['attiva'=>true];}
function hrInviaEmail(...$args): array {global $emailTest;$emailTest[]=$args;return ['inviata'=>true];}
require dirname(__DIR__,2).'/includes/hr_notifiche.php';
$x->exec("CREATE TABLE hr_configurazioni(codice TEXT,valore TEXT,attivo INTEGER);
 CREATE TABLE hr_canali_notifica(id_canale_notifica INTEGER,codice TEXT,attivo INTEGER);
 CREATE TABLE hr_tipi_recapito(id_tipo_recapito INTEGER,codice TEXT,attivo INTEGER);
 CREATE TABLE hr_recapiti_utenti(id_recapito_utente INTEGER,id_utente INTEGER,id_tipo_recapito INTEGER,valore TEXT,attivo INTEGER,verificato INTEGER,principale INTEGER);
 CREATE TABLE hr_notifiche(id_notifica INTEGER PRIMARY KEY AUTOINCREMENT,tipo_evento TEXT,titolo TEXT,messaggio TEXT,link TEXT,id_richiesta INTEGER,creato_da INTEGER);
 CREATE TABLE hr_notifiche_destinatari(id_notifica INTEGER,id_utente INTEGER,id_canale_notifica INTEGER,inviata INTEGER,letta INTEGER,data_invio TEXT,errore_invio TEXT);
 INSERT INTO hr_configurazioni VALUES('HR_EMAIL_WORKFLOW_ATTIVO','1',1);
 INSERT INTO hr_canali_notifica VALUES(1,'EMAIL',1);
 INSERT INTO hr_tipi_recapito VALUES(1,'EMAIL_PERSONALE',1),(2,'EMAIL_LAVORO',1);
 INSERT INTO hr_recapiti_utenti VALUES(1,1,1,'personale@example.test',1,1,1),(2,1,2,'lavoro@example.test',1,1,1);");
$emailTest=[];
hrCreaNotificaEmailPerUtenti($x,'ALLATTAMENTO_PROGRAMMA','Titolo','Riepilogo',null,null,9,[1],1);
ok(count($emailTest)===1&&$emailTest[0][1]==='personale@example.test','una sola email personale verificata');
ok($emailTest[0][5]===null,'senza tabella della singola richiesta');
hrCreaNotificaEmailPerUtenti($x,'EVENTO_PRECEDENTE','Titolo','Riepilogo',null,null,9,[1]);
ok($emailTest[1][1]==='lavoro@example.test','chiamate precedenti invariate');
$x->exec('UPDATE hr_recapiti_utenti SET verificato=0 WHERE id_tipo_recapito=1');
$res=hrCreaNotificaEmailPerUtenti($x,'ALLATTAMENTO_PROGRAMMA','Titolo','Riepilogo',null,null,9,[1],1);
ok(count($emailTest)===2&&$res['saltate']===1,'non ripiega su mail lavoro se personale assente');
$x->exec('UPDATE hr_recapiti_utenti SET verificato=1 WHERE id_tipo_recapito=1');
$richiesta=allattInserisciManuale($x,'2026-10-13','08:00','09:00');
hrCreaNotificaEmailPerUtenti($x,'EVENTO_PRECEDENTE','Titolo','Riepilogo',null,$richiesta,9,[1]);
ok($emailTest[2][1]==='personale@example.test','email personale della normale richiesta preservata');
ok($emailTest[2][5]===$richiesta,'dettaglio richiesta precedente preservato');
echo "$checks controlli superati\n";
