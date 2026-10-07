# Calendario personale ICS

## Accesso e pagina personale

- Voce `Il mio calendario` sotto `menu.profilo`, risorsa `pagina.mio_calendario`.
- Pagina `mio_calendario.php`: autenticazione obbligatoria, identita' solo da sessione, controllo account attivo nel database. Il link e' mostrato solo se l'utente ha accesso al Calendario assenze. La consultazione non modifica dati. Il feed include le persone e i dettagli autorizzati nel calendario web.
- Link assoluto costruito esclusivamente da `HR_URL_PORTALE` (HTTPS) e username corrente dal DB, non dall'Host della richiesta o da parametri del browser.
- Campo readonly selezionabile, copia tramite Clipboard API con fallback e selezione manuale, pulsanti da almeno 44px e layout responsive.
- Pagina privata con `no-store`, `no-referrer` e `noindex`. Non carica script esterni per la copia.

## Token e inizializzazione

- Per la prima inizializzazione, caricare i file PHP del calendario, il helper condiviso e il menu, poi eseguire `sql/2026-09-30_ics_tutti_utenti.sql`. Per l'estensione del feed, se i token sono gia' stati generati, non rieseguire SQL.
- Crea un token casuale di 32 byte (64 caratteri esadecimali) per ogni account esistente. La generazione avviene sul server MySQL tramite `RANDOM_BYTES(32)`, non nel file distribuito.
- Il token recuperabile e' conservato in `hr_ics_token_utenti`: serve per mostrare nuovamente il link. Questa tabella e i backup contengono segreti e devono restare riservati; mai esportarli in GitHub o nei pacchetti di distribuzione.
- L'hash SHA-256 resta in `hr_configurazioni` con codice `HR_ICS_TOKEN_SHA256_USER_<id_utente>`, compatibile con l'endpoint esistente.
- L'inizializzazione sostituisce una sola volta eventuali precedenti token di prova dei quali si possiede solo l'hash. Il precedente abbonamento deve essere sostituito con il nuovo link della pagina personale.
- Lo script si puo' rieseguire: genera solo i token mancanti, inclusi quelli per account creati successivamente; conserva i token gia' presenti e non riattiva configurazioni revocate. Non ripara automaticamente coppie token/hash alterate manualmente.
- Le nuove assegnazioni e il menu sono transazionali; la creazione iniziale della tabella avviene prima della transazione. Precheck InnoDB e Profilo, verifiche per conteggio effettivo, COMMIT/ROLLBACK condizionale senza dipendere da ROW_COUNT.
- Nessuna modifica a password, flag, ruoli, permessi, recapiti, richieste o diritti HR.
- I token sono indipendenti dalla password: il cambio password non cambia il link.
- Lo username resta un parametro dell'endpoint: una futura rinomina richiede di aggiornare l'abbonamento, senza cambiare il token.

## Endpoint e visibilita' condivisa con il calendario web

`/calendario_personale_ics.php?utente=<username>&token=<token_personale>`

URL e token gia' creati restano invariati. Il token identifica il proprietario; cookie, sessione e altri parametri non possono ampliare i suoi permessi.

`includes/hr_calendario.php` e' la fonte comune per web e ICS:

- permessi correnti da account attivo, ruoli attivi e assegnazioni non scadute; precedenza admin globale, permessi read/write espliciti (anche negati), fallback legacy view/edit per le pagine;
- ambito: proprietario, riporti diretti di primo livello, membri dei gruppi correnti; tutti gli utenti attivi solo se autorizzati dalla configurazione HR o dal permesso globale;
- nessuna ricorsione ai riporti dei propri riporti; persone comuni a gruppo e gerarchia incluse una sola volta;
- richieste approvate; richieste in attesa soltanto per proprietario, approvatore assegnato con approvazione in attesa, oppure permesso globale sui pendenti;
- tipologie attive e visibili nel calendario; persone inattive, bozze, rifiuti e annullamenti esclusi;
- dettaglio proprio completo; dettaglio delle altre persone secondo mostra_dettaglio_hr/responsabili/colleghi, con la stessa priorita' del calendario web;
- causale, oggetto e categoria ICS nascosti quando il dettaglio non e' autorizzato; rimane la descrizione generica dello stato presenza. Le note non vengono mai esportate.

Il titolo di ogni evento comprende il nominativo, per distinguere le persone nel calendario esterno. Il codice richiesta rimane presente solo negli eventi del proprietario. Le richieste in attesa hanno prefisso `[In attesa]` e stato ICS `TENTATIVE`; le approvate `CONFIRMED`. UID stabili e invariati per richiesta e periodo. Smart working trasparente/free, altri eventi busy.

Il feed contiene gli eventi autorizzati senza limitarsi alle due settimane o al mese selezionato nella pagina web: vista/data e il filtro Vedi tutti della pagina non modificano l'ambito autorizzativo. Le giornate verdi senza assenza non generano eventi ICS.

Permessi, relazioni e gruppi vengono riletti ad ogni aggiornamento del feed. Se viene revocato il permesso di leggere il calendario, il feed risponde 403 anche con token valido. Errori durante il caricamento producono 503 senza feed parziale. Date non interpretabili non producono VEVENT incompleti. Il feed ha intestazioni private/no-store e no-referrer.

Gli aggiornamenti e la scomparsa degli eventi non piu' autorizzati dipendono dalla frequenza del client calendario. Usare un abbonamento da URL, non una singola importazione.

## Revoca

Impostare `attivo=0` sulla configurazione `HR_ICS_TOKEN_SHA256_USER_<id_utente>`. Il feed nega l'accesso e la pagina personale non mostra il link. La riesecuzione dello script conserva la revoca. Disattivare l'account nega comunque l'accesso, indipendentemente dal token. I dati gia' scaricati da un client non possono essere cancellati a distanza.

## Installazione dell'estensione al calendario condiviso

1. Caricare il nuovo `includes/hr_calendario.php` nella cartella includes.
2. Sostituire `calendario_assenze.php`, `calendario_personale_ics.php` e `mio_calendario.php` nella radice con i file completi del pacchetto.
3. Nessuno script SQL da eseguire: tabelle, link e token gia' generati restano validi.
4. Gli abbonamenti esistenti riceveranno anche gli eventi delle persone autorizzate al successivo aggiornamento del client; non occorre sostituire i link.

Commit summary: centralizza visibilita', permessi e selezione eventi tra calendario web e ICS; estende il feed alle persone autorizzate, mantenendo privacy dei dettagli e dei pendenti, e aggiorna la spiegazione nella pagina personale.

## Verifiche della modifica

Sintassi PHP 8.3, query su fixture SQL in MariaDB 10.11 e confronto degli ID evento web/ICS per responsabile, collega, Direzione con visibilita' globale senza dettagli/pendenti, HR e amministratore. Verificati anche livelli gerarchici, gruppi scaduti/futuri, account/ruoli inattivi, permessi scaduti/revocati, token errato/revocato, duplicati di approvazione, categorie e oggetti riservati, UID, giornata intera e conversione delle ore nel fuso Europe/Rome. Test di precedenza permessi atomici/legacy e piu' ruoli. HTML/CSS/JavaScript del calendario web conservati; funzioni di scope e dettaglio estratte senza modifiche.

## Eventi da oggi in avanti — 7 ottobre 2026

Il feed esporta solo i periodi con data finale uguale o successiva a oggi, calcolato in Europe/Rome. Include gli eventi a ore di tutta la giornata odierna e le assenze iniziate prima di oggi ma ancora in corso. Le date originali, gli UID e la separazione dei periodi sono conservati. Nessuna scadenza pratica per gli eventi futuri: la query usa il limite massimo DATE del database, 9999-12-31.

Il filtro usa l'intervallo già previsto da hrEventiCalendario; calendario web, scope, privacy e autorizzazioni ai pendenti non cambiano. Token e collegamenti restano validi. Il feed non cancella copie di eventi precedentemente importate manualmente in un calendario esterno.

Verificati: lint PHP; esecuzione del feed con query comune reale e PDO simulato (ieri, oggi, futuro, a cavallo di oggi, richieste con più periodi, scope e mascheramento); rifiuto token errato/revocato e accesso negato; errore DB senza feed parziale; data italiana al confine UTC e in ora solare. Nessuna scrittura sul database di produzione.
