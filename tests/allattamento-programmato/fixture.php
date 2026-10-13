<?php
declare(strict_types=1);
// SQLite reale per transazioni, rollback, FK, query e lettura delle richieste.
// Il solo adattamento SQL rimuove FOR UPDATE (SQLite serializza la scrittura)
// e registra le funzioni MySQL usate dalle regole condivise.
class AllattTestPDO extends PDO
{
    public int $locks = 0;
    public function __construct() {
        parent::__construct('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->exec('PRAGMA foreign_keys=ON');
        $this->sqliteCreateFunction('NOW', static fn()=>date('Y-m-d H:i:s'));
        $this->sqliteCreateFunction('CURDATE', static fn()=>hrAllattOggi());
        $this->sqliteCreateFunction('TIMEDIFF', static fn($a,$b)=>hrAllattMinuti((string)$a)*60-hrAllattMinuti((string)$b)*60);
        $this->sqliteCreateFunction('TIME_TO_SEC', static fn($v)=>$v);
    }
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
        if (str_contains($query,'FOR UPDATE')) { $this->locks++; $query=str_replace('FOR UPDATE','',$query); }
        return $fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$args);
    }
}
function allattFixture(): AllattTestPDO
{
    $d=new AllattTestPDO();
    $d->exec("CREATE TABLE aut_utenti(id_utente INTEGER PRIMARY KEY, nome TEXT, cognome TEXT, username TEXT, attivo INTEGER);
        CREATE TABLE hr_profili_dipendenti(id_utente INTEGER PRIMARY KEY, attivo INTEGER);
        CREATE TABLE aut_risorse(id_risorsa INTEGER PRIMARY KEY,codice_risorsa TEXT);
        CREATE TABLE hr_benefici_utenti(id_utente INTEGER, codice_beneficio TEXT, data_inizio TEXT, data_fine TEXT,attivo INTEGER);
        CREATE TABLE hr_chiusure_aziendali(id_chiusura INTEGER PRIMARY KEY,descrizione TEXT,data_da TEXT,data_a TEXT,attivo INTEGER);
        CREATE TABLE hr_stati_richiesta(id_stato_richiesta INTEGER PRIMARY KEY,codice TEXT,attivo INTEGER);
        CREATE TABLE hr_tipologie_evento(id_tipologia_evento INTEGER PRIMARY KEY,codice TEXT,attivo INTEGER,consente_ore INTEGER);
        CREATE TABLE hr_relazioni_organizzative(id_relazione_organizzativa INTEGER PRIMARY KEY,id_utente INTEGER,id_utente_collegato INTEGER,id_tipo_relazione INTEGER,attiva INTEGER,data_inizio TEXT,data_fine TEXT);
        CREATE TABLE hr_tipi_relazione_organizzativa(id_tipo_relazione INTEGER PRIMARY KEY,codice TEXT,attivo INTEGER);
        CREATE TABLE hr_richieste(id_richiesta INTEGER PRIMARY KEY AUTOINCREMENT,codice_richiesta TEXT UNIQUE,id_utente_richiedente INTEGER,id_tipologia_evento INTEGER,id_stato_richiesta INTEGER,id_responsabile_corrente INTEGER,data_invio TEXT,data_chiusura TEXT,data_aggiornamento TEXT,origine TEXT,annullata_da_richiedente INTEGER DEFAULT 0);
        CREATE TABLE hr_richieste_periodi(id_richiesta_periodo INTEGER PRIMARY KEY AUTOINCREMENT,id_richiesta INTEGER,tipo_periodo TEXT,data_da TEXT,data_a TEXT,ora_da TEXT,ora_a TEXT,giornata_intera INTEGER,minuti_totali INTEGER,ordinamento INTEGER);
        CREATE TABLE hr_richieste_storico(id_storico INTEGER PRIMARY KEY AUTOINCREMENT,id_richiesta INTEGER,azione TEXT,id_utente_azione INTEGER,dettagli TEXT,origine TEXT);
        CREATE TABLE hr_richieste_approvazioni(id_richiesta INTEGER,stato_approvazione TEXT,data_risposta TEXT);
        INSERT INTO aut_utenti VALUES(1,'Persona','Demo','Demo',1),(2,'Altra','Persona','Altra',1),(9,'Operatore','HR','HR',1);
        INSERT INTO hr_profili_dipendenti VALUES(1,1),(2,1);
        INSERT INTO aut_risorse VALUES(1,'pagina.assenze');
        INSERT INTO hr_benefici_utenti VALUES(1,'ALLATTAMENTO','2026-10-01','2027-03-06',1),(2,'ALLATTAMENTO','2026-10-01','2027-03-06',1);
        INSERT INTO hr_stati_richiesta VALUES(1,'APPROVATA',1),(2,'IN_ATTESA',1),(3,'ANNULLATA',1),(4,'BOZZA',1),(5,'RIFIUTATA',1);
        INSERT INTO hr_tipologie_evento VALUES(1,'ALLATTAMENTO',1,1),(2,'FERIE',1,1);");
    $migration=file_get_contents(dirname(__DIR__,2).'/sql/2026-10-13_allattamento_programmato.sql');
    $migration=preg_replace('/BIGINT UNSIGNED NOT NULL AUTO_INCREMENT/','INTEGER PRIMARY KEY AUTOINCREMENT',$migration);
    $migration=preg_replace('/^\s*PRIMARY KEY \((id_programma|id_storico)\),\s*$/m','',$migration);
    $migration=preg_replace('/^\s*KEY .*\n/m','',$migration);
    $migration=preg_replace('/\) ENGINE=InnoDB[^;]*;/',');',$migration);
    $d->exec($migration); $d->exec($migration);
    return $d;
}
function allattInput(array $extra=[]): array
{
    return array_replace(['azione_programma'=>'crea','id_utente'=>1,'data_da'=>'2026-10-13','data_a'=>'2027-03-06',
        'giorni'=>[1,2,3,4,5],'ora_da'=>['08:00','16:00'],'ora_a'=>['09:00','17:00']],$extra);
}
function allattInserisciManuale(PDO $d,string $giorno,string $da,string $a,int $tipo=1,int $stato=1,int $utente=1): int
{
    $s=$d->prepare("INSERT INTO hr_richieste(codice_richiesta,id_utente_richiedente,id_tipologia_evento,id_stato_richiesta) VALUES(:codice,:utente,:tipo,:stato)");
    $s->execute(['codice'=>bin2hex(random_bytes(8)),'utente'=>$utente,'tipo'=>$tipo,'stato'=>$stato]);$id=(int)$d->lastInsertId();
    $s=$d->prepare("INSERT INTO hr_richieste_periodi(id_richiesta,tipo_periodo,data_da,data_a,ora_da,ora_a,ordinamento) VALUES(:id,'ORE',:inizio,:fine,:da,:a,1)");
    $s->execute(['id'=>$id,'inizio'=>$giorno,'fine'=>$giorno,'da'=>$da,'a'=>$a]);return $id;
}
