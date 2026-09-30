# LEVANTE WEB - Contratti funzionali HR

Questo documento raccoglie i comportamenti gia' consolidati del modulo HR assenze.
Ogni modifica futura deve preservarli, salvo decisione esplicita contraria.

## Regole operative di sviluppo

1. Prima di ogni modifica verificare la baseline GitHub sulla pagina commit del branch `main`.
2. Se un commit e' stato confermato come eseguito, deve essere considerato presente; se non risulta subito visibile, rileggere/aggiornare GitHub prima di procedere.
3. Ogni pacchetto deve contenere solo file completi realmente modificati.
4. Ogni pacchetto deve avere un commit summary chiaro.
5. Prima di refactoring o pulizia codice verificare i comportamenti gia' approvati della pagina interessata.
6. Non rimuovere logiche locali utili mentre si centralizzano componenti comuni.

## Contratti funzionali - assenze.php

### Date

- Se il tipo richiesta e' `GIORNI`, quando viene compilato `Dal giorno`, il campo `Al giorno` deve essere precompilato con lo stesso valore se vuoto.
- Se il tipo richiesta e' `ORE`, `Al giorno` deve essere sincronizzato con `Dal giorno`.

### Orari

- Per richieste a ore, gli orari ammessi sono compresi tra le 08:00 e le 17:00 e lavorano a step di 15 minuti.
- Nell'interfaccia le ore selezionabili sono 08, 09, 10, ..., 17 e i minuti selezionabili sono 00, 15, 30, 45.
- Quando viene compilato `Dalle ore`, il campo `Alle ore` deve proporre automaticamente un orario pari a +1 ora, senza superare le 17:00.
- Il controllo sovrapposizioni deve distinguere correttamente richieste a giorni e richieste a ore.
- Le richieste a giorni bloccano l'intero periodo.
- Le richieste a ore bloccano solo se la fascia oraria si sovrappone realmente.

### Validazioni

- L'email personale e' facoltativa: la sua assenza o mancata verifica non deve impedire l'inserimento di richieste personali ne' disabilitare il pulsante di registrazione.
- In assenza di email personale attiva, valida e verificata, la pagina mostra un avviso informativo con link a `miei_recapiti.php`: le richieste restano inseribili e consultabili nello storico, ma gli aggiornamenti personali via email non vengono recapitati.
- Il controllo dell'avviso deve usare gli stessi requisiti del recapito utilizzato per le notifiche al richiedente. Le notifiche ai responsabili e a HR restano invariate.

- Per una richiesta personale di un utente non HR non devono essere accettate date retroattive: la prima data selezionabile e' oggi, oppure il primo giorno del primo mese aperto se il mese corrente e' gia' chiuso.
- Un responsabile, quando inserisce una richiesta in modalita' delegata per un proprio riporto diretto, puo' inserire anche una data retroattiva purche' il periodo ricada interamente in mesi non ancora chiusi da HR.
- La possibilita' di inserimento retroattivo del responsabile non si applica alle sue richieste personali.
- I mesi chiusi da HR non devono essere utilizzabili dai normali utenti o dai responsabili; il controllo deve esistere sia lato interfaccia sia lato server.
- HR mantiene la possibilita' tecnica di operare sui periodi chiusi per le rettifiche autorizzate.
- Non devono essere accettate sovrapposizioni non consentite.
- I messaggi di errore devono essere chiari e coerenti con gli alert del sito.

### Approvazione e informazione al responsabile

- Se il dipendente ha Qualifica INPS `IMPIEGATO`, le tipologie `VISITA_CLIENTE`, `VISITA_FORNITORE` e `FORMAZIONE` sono auto-approvate anche se la tipologia generale richiede normalmente approvazione.
- Per queste tre tipologie, se esiste un responsabile diretto/funzionale, il responsabile riceve una email di sola informazione e non deve confermare nulla.
- Il `PERMESSO LEGGE 104` non richiede approvazione del responsabile. Se esiste un responsabile diretto/funzionale, il responsabile riceve una email di sola informazione.
- Le email informative al responsabile devono indicare esplicitamente che non e' richiesta alcuna approvazione.
- Nelle email al responsabile, quando esistono, devono essere mostrate anche le altre richieste attive o in attesa nello stesso periodo, limitate ai dipendenti di cui il destinatario e' responsabile diretto/funzionale; per le altre richieste, la tipologia deve rispettare `mostra_dettaglio_responsabili` e restare generica quando il dettaglio non e' autorizzato.
- Richiedente e responsabile possono annullare una richiesta auto-approvata finche' lo stato e il periodo lo consentono secondo le regole generali; i mesi chiusi restano bloccati ai non HR.
- Il campo `annullata_da_richiedente` deve valere 1 solo quando l'annullamento e' eseguito dal richiedente stesso.

### Causali HR riservate

- Le causali `ALLATTAMENTO` e `CONGEDO_STRAORDINARIO_DISABILI` sono selezionabili solo dagli utenti con ruolo `hr_responsabile_personale`, indipendentemente dallo username e dal prefisso `test_`.
- La rinomina di un account non deve modificarne password, ruoli, permessi o diritti HR.
- La restrizione deve esistere sia nell'interfaccia sia nella validazione server, per impedire inserimenti forzati da altri utenti.
- Le due causali sono disponibili per un dipendente solo se il corrispondente diritto e' attivo in `hr_benefici_utenti` per l'intero periodo richiesto.
- Entrambe sono trattate come assenze personali: stato presenza `ASSENTE`, nessuna approvazione, dettaglio non mostrato a colleghi o responsabili, dettaglio visibile a HR.
- Nel calendario la descrizione pubblica deve restare generica (`Assente`).
- Sono consentiti sia inserimenti a giorni sia a ore, mantenendo le regole generali su date, orari, sovrapposizioni e mesi chiusi.

### Creazione nuovo utente

- `utente_nuovo.php` crea in una sola transazione l'account portale, il profilo HR, l'eventuale ruolo, l'eventuale responsabile e i recapiti email.
- Campi richiesti: username, nome, cognome, Qualifica INPS e password iniziale con conferma.
- La matricola e' facoltativa ma, se valorizzata, deve restare univoca.
- La Qualifica INPS ammessa e' `OPERAIO` o `IMPIEGATO` e viene salvata in `hr_profili_dipendenti.qualifica_inps`.
- Il ruolo `interno_base` e' preselezionato per i nuovi utenti quando disponibile, ma l'amministratore puo' scegliere un altro ruolo o nessun ruolo.
- Il responsabile e' facoltativo; quando valorizzato viene registrato in `hr_relazioni_organizzative` usando il tipo `RESPONSABILE_FUNZIONALE` se disponibile, altrimenti `RESPONSABILE_DIRETTO`.
- Email di lavoro ed email personale sono salvate come recapiti HR distinti. L'email di lavoro ha priorita' nelle notifiche; in sua assenza viene usata quella personale, con `aut_utenti.email` come fallback anagrafico coerente.
- Le email inserite da amministrazione/HR in fase di creazione sono considerate confermate.
- La password iniziale deve avere almeno 6 caratteri e il nuovo utente deve cambiarla al primo accesso.
- La creazione e' atomica: se uno dei salvataggi fallisce, la transazione viene annullata per evitare account e profilo HR parzialmente creati.

### Gestione ruoli utenti

- `ruoli_utenti.php` e' la pagina dedicata alla modifica del ruolo degli utenti gia' esistenti; la creazione iniziale del ruolo resta in `utente_nuovo.php`.
- La pagina usa una sola vista responsive: tabella compatta su desktop/tablet e schede su smartphone, senza duplicare gli stessi utenti in un archivio separato.
- Il riepilogo dei ruoli disponibili deve restare compatto e mostrare il numero di utenti assegnati a ciascun ruolo.
- Nel riepilogo compatto devono comparire solo i ruoli con almeno un utente assegnato; i ruoli attivi con conteggio zero restano comunque disponibili nei selettori di assegnazione.
- Se nessun ruolo risulta assegnato, il riepilogo deve mostrare il messaggio `Nessun ruolo attualmente assegnato.`.
- Il salvataggio deve aggiornare esclusivamente gli utenti per i quali il ruolo cambia realmente; le assegnazioni invariate non devono essere disattivate/reinserite e non devono perdere la decorrenza originaria.
- L'account tecnico `admin` / `amministratore` e' protetto e non puo' cambiare ruolo dalla pagina.
- Se un utente possiede piu' ruoli attivi, la pagina non deve ridurli automaticamente a un solo ruolo: la selezione iniziale resta su `Nessuna modifica` finche' l'amministratore non effettua una scelta esplicita.
- Se il ruolo attualmente assegnato non e' piu' disponibile tra i ruoli attivi, non deve essere rimosso da un salvataggio generale non intenzionale.
- Il filtro rapido della tabella deve cercare nei dati effettivi dell'utente e del ruolo corrente, senza essere contaminato dalle opzioni non selezionate presenti nelle tendine.

### Benefici e diritti HR

- La pagina `benefici_hr.php` usa `hr_benefici_utenti` come registro unico per `LEGGE_104`, `ALLATTAMENTO`, `CONGEDO_STRAORDINARIO_DISABILI` e `SMART_WORKING`.
- La pagina deve presentare una zona di assegnazione per scegliere dipendente, diritto, decorrenza ed eventuali note.
- Il plafond mensile e l'equivalenza giornata sono richiesti solo per `LEGGE_104`.
- L'elenco principale mostra solo assegnazioni attive, non tutti i dipendenti.
- La revoca disattiva l'assegnazione senza cancellarne la registrazione; una successiva riassegnazione riattiva e aggiorna la stessa coppia utente/beneficio.

### UI

- Il filtro rapido deve usare il comportamento comune centralizzato.
- Badge, pulsanti, alert e campi devono rimanere coerenti col design system.
- Layout desktop/tablet/smartphone deve rimanere ordinato e leggibile.

## Contratti funzionali - relazioni_organizzative.php

- La pagina deve mantenere creazione, consultazione, filtro, storico e chiusura delle relazioni senza modificare la logica dati.
- Il modulo di creazione nuova relazione resta chiuso di default e si apre solo quando serve.
- La vista principale deve mostrare una riga compatta per ogni responsabile/referente con il numero di collaboratori collegati; il dettaglio dei collaboratori si espande su richiesta.
- Nel dettaglio del responsabile non va ripetuta inutilmente la frase "risponde funzionalmente a" per ogni collaboratore; periodo, note ed eventuale indicazione Test/Reale restano disponibili.
- L'archivio completo delle relazioni resta disponibile ma chiuso di default; quando aperto conserva filtro rapido, stato e azione di chiusura relazione.
- La visibilita gerarchica resta limitata al primo livello diretto.
- La vista deve restare responsive su desktop, tablet e smartphone.

## Contratti funzionali - approvazioni_assenze.php

- La pagina deve mostrare le richieste coerenti con i filtri impostati.
- Il filtro rapido deve cercare solo nella tabella gia' caricata.
- Il rifiuto deve richiedere una motivazione.
- L'approvazione puo' essere confermata senza nota.
- Badge e stati devono essere coerenti con `assenze.php`.
- La vista deve restare responsive.

## Contratti funzionali - calendario_assenze.php

- Il calendario deve rispettare le regole di visibilita' e privacy delle tipologie evento.
- Le richieste approvate devono essere visibili secondo ruolo, gruppo e relazione organizzativa.
- La visibilita' gerarchica deve fermarsi al primo livello inferiore, non deve essere ricorsiva.
- Lo smart working deve essere rappresentato in verde pieno, distinto dal verde tenue della presenza, sia per giornata intera sia per assenze/impegni a ore.
- Ordine righe calendario: utente corrente; riporti diretti per cognome crescente; membri dei gruppi non gia' presenti come riporti diretti per cognome crescente; eventuali altri utenti visibili globalmente per cognome crescente.
- I nominativi restano nel formato "Cognome N.".
- Accanto ai riporti diretti deve comparire un'icona gerarchica; accanto ai membri dei gruppi un'icona gruppo. L'utente corrente non necessita di icona aggiuntiva.

## Contratti dati HR

- La struttura HR deve restare autonoma con tabelle `hr_*`.
- L'integrazione con utenti e permessi deve avvenire tramite `aut_utenti`, `aut_risorse`, `aut_ruoli` e `aut_ruoli_permessi`.
- Non aggiungere campi HR sparsi in `aut_utenti` salvo decisione esplicita.
- `hr_gruppi_utenti` usa `id_gruppo_lavoro` e `ruolo_nel_gruppo`.
- Non esiste `hr_ruoli_gruppo`.

## Checklist minima prima di consegnare modifiche HR

- Sintassi PHP verificata.
- Se modificata una pagina con JavaScript, verificare che gli automatismi consolidati siano ancora presenti.
- Se modificati CSS o layout, verificare responsive e coerenza campi/pulsanti/badge.
- Se modificati permessi o menu, verificare coerenza con `aut_risorse`.
- Se modificato SQL, indicare se va eseguito o se e' solo storico/documentazione.


### Qualifica INPS

- La qualifica INPS e' memorizzata nel profilo HR del dipendente, non in `aut_utenti`.
- Valori inizialmente ammessi: `OPERAIO` e `IMPIEGATO`.
- La qualifica e' visibile nella pagina `utenti.php`.
- La modifica della qualifica dalla pagina utenti e' consentita solo al ruolo `admin_portale` e non durante la modalita "Visualizza come".
- Gli utenti attivi non presenti nell'elenco HR possono restare senza qualifica finche' HR non fornisce il dato.


## Contratti funzionali - calendario personale ICS

- Ogni utente autenticato puo' consultare esclusivamente il proprio collegamento in `Il mio calendario`, sotto il menu personale. Il feed richiede il permesso di leggere il Calendario assenze, verificato sul DB corrente.
- Il link deve essere non modificabile, selezionabile e copiabile con un pulsante su PC e smartphone.
- La pagina spiega in modo semplice l'abbonamento da URL e i tempi di aggiornamento del client.
- Token individuali stabili e indipendenti dalla password. La rigenerazione non deve avvenire durante la normale consultazione ne' al cambio password.
- La migrazione genera solo i token mancanti e conserva successive revoche; i precedenti token di prova vengono sostituiti una sola volta.
- Username corrente letto dal DB, identita' proprietario dalla sessione. Nessun parametro puo' selezionare un altro utente.
- Token recuperabili solo nella tabella riservata `hr_ics_token_utenti`; hash di verifica nelle configurazioni. Nessun segreto nel repository o nei file distribuiti.
- Il feed comprende gli eventi di tutte le persone autorizzate nel calendario web: proprietario, riporti diretti di primo livello, gruppi ed eventuale ambito globale. Non include livelli gerarchici ulteriori.
- Web e ICS usano `includes/hr_calendario.php` per permessi correnti, scope, selezione richieste e mascheramento dettagli.
- Pendenti visibili solo al proprietario, all'approvatore assegnato in attesa, o con permesso globale dedicato.
- Nominativo nei titoli degli eventi. Causali, oggetti e categorie ICS restano generici quando manca il diritto al dettaglio. Note sempre escluse, codice richiesta solo per eventi propri.
- UID, URL e token restano stabili. Account inattivo, token revocato o permesso calendario revocato non danno accesso.
- Le giornate senza eventi non generano impegni ICS; il feed non e' limitato alla vista temporale selezionata sul web.


