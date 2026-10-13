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

- Le causali `MALATTIA` e `CONGEDO_STRAORDINARIO_DISABILI` sono selezionabili e registrabili dagli utenti con ruolo `hr_responsabile_personale` e dall'amministratore globale (`utenteAdminGlobale()`), indipendentemente dal prefisso `test_`. In `assenze.php` la stessa capacità abilita le funzioni HR di riclassificazione Altro, inserimento retroattivo e gestione dei mesi chiusi. Restano i controlli sui benefici individuali e sulle sovrapposizioni; il profilo corrente in "Visualizza come" non eredita le capacità dell'amministratore originario. L'estensione non assegna ruoli HR né modifica i destinatari dei riepiloghi email.
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




## Contratti funzionali - layout e trasporto email HR

- Tutte le email HR, compresa la verifica del recapito, usano la cornice, gli stili e il trasporto comuni di `includes/hr_email.php`.
- Standard grafico e tecnico: `docs/hr-email-layout-standard.md`. I nuovi invii non devono usare template autonomi o HTML 8bit su una sola riga.
- Il layout deve mantenere saluti, dettagli, badge stato, codice e CTA del workflow; font e footer devono essere coerenti anche per richieste a giorni e a ore.
- I riepiloghi automatici, gli aggiornamenti e le prove manuali condividono lo stesso renderer. HR vede motivi e pendenti; BASE solo approvate senza motivo, conservando l'oggetto breve.
- Qualsiasi modifica ai template o al trasporto deve superare i test MIME e di rendering in `tests/email-layout/run_tests.py`, oltre al controllo nel client email effettivamente usato prima del go live.



## Creazione reparti e centri di costo — 1 ottobre 2026

- Profili dipendenti offre accessi a reparti.php e centri_costo.php.
- Le nuove pagine condividono i permessi di profili_dipendenti; impersonazione senza scritture.
- Creazione con codice e nome, voce subito attiva, nessuna modifica delle assegnazioni esistenti.
- Duplicati di codice respinti anche per voci inattive; CSRF, transazione e serializzazione per tabella.
- Nessuna migrazione SQL; restano valide tutte le precedenti regole HR, ICS, recapiti ed email.

## Profili dipendenti e responsabilità — 1 ottobre 2026

- Elenco compatto, filtri e un solo modulo completo per persona selezionata.
- Account attivo e profilo HR attivo restano dati diversi; gli accessi non sono modificati dal profilo.
- GET senza scritture DB; profili mancanti creati solo con POST esplicito e autorizzato.
- Profili e Relazioni organizzative condividono il salvataggio del responsabile, con transazione e lock per dipendente.
- Responsabile invariato: preservare date, scadenze e pianificazioni. Cambi: conservare storico e note; impedire conflitti con assegnazioni pianificate.
- Consultazione ed export considerano le date effettive delle relazioni e dei team.
- Permessi preesistenti, privacy calendario, ICS, email e approvazioni conservati.

## Filtro temporale ICS — 7 ottobre 2026

- ICS include oggi e futuro secondo Europe/Rome; esclude i periodi terminati prima di oggi.
- Assenze iniziate prima di oggi ma ancora in corso restano incluse con date e UID originali.
- Nessun cambiamento a token, password, scope, privacy, pendenti o storico del calendario web.

## Tipologie nella combobox Assenze — 9 ottobre 2026

- Raggruppamento visivo: Permessi e assenze personali prima, Assenze per lavoro dopo; Altro, quando consentito, è l’ultima opzione.
- Visita cliente, Visita fornitore, Formazione, Fiera, Trasferta e Smart working sono nel gruppo di lavoro; le altre tipologie restano nel gruppo personale.
- Permesso è visualizzato come Permesso (ROL) nella combobox principale. Codice PERMESSO, ID e descrizione nel database non sono modificati.
- Conservati i filtri HR/admin, benefici configurati, visibilità di Altro, selezione del modulo e ordine preesistente dentro ciascun gruppo.
- Backend, salvataggi, durate, approvazioni, notifiche/email e permessi invariati; nessuna migrazione SQL.
- Verificati lint PHP e rendering prima/dopo per sette combinazioni di ruolo, Altro e benefici; stessi ID e selezioni, nuova etichetta e ordinamento corretto.



## 2026-10-09 — Chiusure aziendali e allattamento a ore

- HR (`hr_responsabile_personale`) e amministratore globale gestiscono le chiusure aziendali dalla pagina `chiusure_aziendali.php`, raggiungibile dal menu e da Configurazione assenze. Inserimento, modifica e disattivazione richiedono permesso di scrittura e CSRF; Visualizza come resta in sola lettura.
- `hr_chiusure_aziendali` è l'unico archivio delle chiusure. È distinto dalle chiusure mensili amministrative: gli intervalli aziendali bloccano tutte le causali, inclusi gli inserimenti HR/admin, ore/giorni e richieste che attraversano anche un solo giorno di chiusura. Estremi compresi.
- Non vengono generate richieste fittizie per i dipendenti né cancellate le richieste preesistenti. La gestione chiusure mostra il numero di richieste approvate/in attesa sovrapposte da verificare; HR le gestisce in Assenze. Le approvazioni di richieste che comprendono una chiusura sono bloccate; annullamento e rifiuto rimangono possibili secondo i permessi precedenti.
- Le chiusure attive sono consultabili anche nel modulo Nuova richiesta. Nessuna modifica allo scope del calendario o all'ICS.
- La validità dell'allattamento usa le date già presenti in `hr_benefici_utenti`: HR/admin devono indicare dal e al. Nessuna duplicazione delle assegnazioni. Le assegnazioni vecchie senza data finale rimangono registrate ma non consentono nuove richieste finché HR completa il periodo.
- ALLATTAMENTO è ora disponibile anche al dipendente abilitato nel perimetro autorizzato, solo a ore per un giorno alla volta. Dal/al compresi, massimo fisso di 120 minuti complessivi per dipendente/giorno, anche sommando richieste distinte o più periodi della stessa richiesta.
- Nel conteggio entrano APPROVATA e IN_ATTESA; ANNULLATA e RIFIUTATA non consumano il limite. Le vecchie registrazioni a giorni sono conservate e occupano l'intero limite dei giorni coinvolti.
- Lo stesso controllo server viene eseguito su nuova richiesta, riclassificazione Altro verso ALLATTAMENTO e approvazione, escludendo dal conteggio la richiesta riclassificata/approvata. Non sono previsti bypass per HR/admin.
- `includes/hr_regole_assenze.php` è il servizio comune. Prima delle letture di controllo ogni transazione acquisisce FOR UPDATE sulla risorsa esistente `pagina.assenze` (nessun cambiamento ai permessi): richieste, assegnazioni e chiusure condividono l'ordine di acquisizione per impedire invii simultanei oltre il limite o salvataggi concorrenti durante una nuova chiusura.
- Tipologie, approvazioni, Legge 104, altri benefici, privacy, email, token e password conservano le regole precedenti salvo le modifiche esplicite sopra. Le operazioni di scrittura sui benefici restano riservate a HR/admin con il relativo permesso.
- Eseguire prima la migration `sql/2026-10-09_chiusure_aziendali_allattamento.sql`, poi caricare il servizio comune e tutti i PHP indicati in `docs/aggiornamento-2026-10-09-chiusure-allattamento.md`. La migration è ripetibile e non assegna date finali arbitrarie.



## 2026-10-09 — Visualizzazione chiusure e controllo password nel login

- In Calendario assenze, nelle viste Giorno, 2 settimane e Mese, le chiusure aziendali attive sono lette da `hr_chiusure_aziendali`, con gli stessi estremi inclusivi del blocco di inserimento.
- Il grigio tenue e un quadratino identificano le chiusure, con relativa legenda. Una riga aziendale dedicata e il riepilogo dei periodi le rendono visibili anche quando non ci sono assenze individuali, senza creare richieste fittizie o ampliare l'elenco delle persone visualizzate.
- Nelle celle delle persone già visualizzate, i giorni chiusi non appaiono disponibili. Le richieste eventualmente preesistenti restano visibili insieme alla chiusura, con i loro colori e le loro regole di riservatezza. Il popup mostra la chiusura e le informazioni individuali già autorizzate, senza annunciare disponibilità nei giorni chiusi.
- Clic, tocco, Invio e Spazio aprono il dettaglio. Scope, filtro Vedi solo assenze/Vedi tutti, ordinamento delle persone, privacy delle causali, visibilità dei pendenti e feed ICS conservano il comportamento precedente. Sabato e domenica rimangono esclusi dalla griglia lavorativa di mese/2 settimane; il riepilogo della chiusura conserva l'intero intervallo e la vista Giorno la mostra anche nel weekend.
- Nel login, un pulsante con l'occhio dentro il campo Password alterna testo visibile/nascosto. È un button type=button, con etichetta accessibile, aria-pressed e area di tocco di 44 pixel. Non invia il modulo né modifica il valore, il nome del campo o la verifica delle credenziali.
- La password è nascosta inizialmente, all'invio e alla riapertura della pagina. Senza JavaScript il campo rimane una normale password e il pulsante è nascosto. Nessuna password viene inserita nell'HTML dal server o salvata dal nuovo controllo.
- Aggiornamento applicativo: solo `calendario_assenze.php` e `login.php` nella cartella principale del sito; nessuno script SQL aggiuntivo. Test e documentazione rimangono su GitHub.



## 2026-10-12 — Editor ruoli coerente con le autorizzazioni effettive

- `permessi_ruoli.php` deve valutare `read/write` espliciti prima degli alias `view/edit`, anche quando negati, come `haPermesso`. Il fallback legacy si applica alle sole risorse `pagina.*`, non alle azioni atomiche.
- La vecchia pagina mostrava e salvava soltanto view/edit: poteva quindi mostrare Scrittura mentre read/write già presenti continuavano a negare menu e accesso. La correzione evidenzia il livello precedente discordante e l'eventuale scrittura senza lettura, senza disattivare i dinieghi nel runtime.
- Salva permessi scrive read/write e gli alias compatibili view/edit nella stessa transazione, soltanto per il ruolo scelto. Il checkbox Menu resta una regola distinta: occorrono lettura e visibilità menu. I contenitori puri ricevono soltanto la regola menu. Le altre azioni create/delete/execute esplicite non vengono riscritte.
- Nessuna modifica ad autorizzazioni.php, layout.php, impersonazione, ruoli utente, calendario, scope, privacy, permessi HR speciali o bypass dell'amministratore globale.
- Per correggere una configurazione preesistente, caricare il file completo `permessi_ruoli.php` nella cartella principale, aprire il ruolo interessato, riselezionare i livelli voluti e salvare. Le eventuali sessioni legacy vanno aggiornate uscendo/rientrando; Visualizza come va riaperto dal profilo amministratore.
- Per operativo_ordini, le impostazioni richieste dalla schermata sono Scrittura + Menu per Assenze e permessi, Permessi tutelati e Calendario assenze; ciò non comporta accesso a tutte le persone, alle causali riservate, alle richieste pendenti globali o alla configurazione HR.
- Test: 162 combinazioni confrontate con le funzioni di autorizzazione reali, oltre a GET dell'editor, salvataggio dei tre livelli, visibilità menu indipendente, isolamento del ruolo selezionato, dinieghi delle azioni riservate e rollback in caso di errore. PDO sostituito: nessuna modifica al database del sito.



## 2026-10-12 — Congedo Straordinario Disabili e rimozione Altro

- `CONGEDO_STRAORDINARIO_DISABILI` è un diritto tutelato assegnato in `hr_benefici_utenti`, nella pagina Benefici e diritti. HR (`hr_responsabile_personale`) e amministratore mantengono le stesse capacità, senza collegamenti al nome/username di Giorgia.
- Le date dal/al sono obbligatorie ed inclusive. Il congedo si registra solo a giorni e solo entro il periodo assegnato; assegnazioni senza fine vanno completate. Nessun plafond mensile della Legge 104 viene applicato al congedo.
- Nuova richiesta, riclassificazione e approvazione condividono il controllo server della validità e della modalità. Restano attivi sovrapposizioni, chiusure aziendali, mesi chiusi e transazioni. La causale non è selezionabile da dipendenti/responsabili ordinari.
- Nel calendario web e ICS la causale e il suo oggetto sono visibili soltanto a HR/admin, anche rispetto al proprietario e a permessi estesi di visualizzazione tipologie. Gli altri vedono Assente, senza categoria ICS o codice della causale nel payload del calendario. Il livello HR è ricavato dai ruoli DB del proprietario del feed, non dalla sessione del browser.
- Nella pagina Assenze, per chi non è HR/admin, le registrazioni di congedo sono etichettate Assente e non mostrano oggetto/note. Anche le email individuali mascherano tipologia, oggetto e note per destinatari non HR/admin; il riepilogo BASE non mostra l'oggetto di questa causale. Il riepilogo HR mantiene il motivo dettagliato e il layout esistente.
- ALTRO non compare più fra le nuove richieste per nessun ruolo. Il server ne blocca l'inserimento; le richieste preesistenti rimangono registrate, visibili con le regole precedenti e riclassificabili da HR/admin. La tipologia non viene disattivata nel DB perché ciò nasconderebbe lo storico dal calendario.
- Il ricostruttore JavaScript comune conserva optgroup personali/lavoro, codici delle opzioni e selezione; non reinserisce ALTRO. Restano invariati Permesso (ROL), Allattamento e gli altri diritti.
- Migration `sql/2026-10-12_congedo_straordinario_disabili.sql` ripetibile, da eseguire prima dei PHP: inserisce la causale se mancante e aggiorna modalità/privacy senza cancellare richieste, token o periodi assegnati.
- Verifiche: 94 scenari dei flussi con PHP reale/PDO sostituito, 20 combinazioni privacy calendario/ICS, filtro email HR/non HR e ricostruzione select eseguita in JavaScript. Nessuna modifica al DB del sito né invio di email reale.



## 2026-10-13 — Visibilità direzionale del calendario

- Nuovo tipo organizzativo `VISIBILITA_CALENDARIO`, descritto come mostra nel calendario di. Si usa la tabella esistente `hr_relazioni_organizzative`: id_utente=A è la persona mostrata, id_utente_collegato=B è il referente che la vede.
- Il collegamento non crea visibilità inversa né transitiva e non modifica la relazione gerarchica o l'approvatore di A. Più A possono essere mostrati a B; A può essere mostrato a più referenti. Le autorizzazioni già provenienti da gruppi, gerarchia o permessi globali restano valide.
- Il nuovo servizio `hrOrgAssegnaVisibilitaCalendario` valida utenti attivi, tipo, date, assenza di autoriferimenti e sovrapposizioni della stessa coppia. Condivide il lock del dipendente con gli altri salvataggi organizzativi, è idempotente per invii identici e non chiude/cancella i responsabili esistenti. I collegamenti si revocano con la chiusura relazione già disponibile.
- Il collegamento è utilizzabile dal calendario solo quando attivo, iniziato e non scaduto, con estremi inclusivi. Come per le relazioni precedenti, la validità stabilisce quando B è autorizzato a consultare A; non ritaglia le date degli eventi. Nessuna nuova iscrizione o rotazione di token ICS.
- Il perimetro calendario condiviso da web e ICS include direttamente A per B con flag scope_calendario. Questo flag non entra nei perimetri gestionali, approvativi o dei report/email. Non viene attribuita lettura della pagina Calendario a chi non la possiede già.
- La sola visibilità calendario non abilita richieste pendenti: restano le regole di proprietario, approvatore assegnato ed eventuale permesso globale preesistente. Un caporeparto con il solo nuovo collegamento vede le richieste approvate.
- Per B non HR/admin, nel nuovo collegamento tutte le assenze personali di A sono Assente, senza oggetto, motivo o categoria ICS specifica, anche rispetto a permessi estesi di tipologia. I dettagli degli impegni di lavoro (visite cliente/fornitore, formazione, fiera, trasferta, smart working) rispettano il flag di dettaglio per responsabili già configurato. HR/admin conservano le proprie autorizzazioni precedenti, incluso il congedo tutelato.
- La pagina Relazioni distingue nella mappa la relazione gerarchica da quella informativa e conserva entrambe quando le persone coincidono. Il calendario aggiunge l'icona occhio e la legenda Solo calendario; ordine di sé, riporti e gruppi precedente preservato.
- Eseguire la migration ripetibile `sql/2026-10-13_visibilita_calendario.sql`, poi caricare i cinque PHP elencati in `docs/aggiornamento-2026-10-13-visibilita-calendario.md`. Nessuna relazione viene assegnata automaticamente.
- Verifica: 46 controlli su servizi, direzione, date, revoca, scope gestionale distinto e privacy web/ICS; regressione del servizio responsabili; 19 scenari del calendario e relativo JavaScript. PHP reale, PDO sostituito; nessuna modifica al database del sito.


## 2026-10-13 — Gruppi di relazione nella mappa organizzativa

- Nei riquadri dei referenti, le persone sono raggruppate per relazione: una sola intestazione Risponde funzionalmente a e una sola intestazione Mostra nel calendario di, con i rispettivi nominativi sotto.
- Responsabile diretto e funzionale condividono il gruppo visivo precedente; le relazioni informative sono separate e usano l'icona occhio. I gruppi vuoti non vengono mostrati.
- Periodi, note, badge, filtro rapido, conteggio delle persone e archivio restano disponibili. La stessa persona con entrambe le relazioni compare nei due gruppi; il totale del referente continua a contare persone distinte.
- Modifica soltanto al rendering di relazioni_organizzative.php: backend, query, CSRF, dati e autorizzazioni invariati. Nessuno script SQL necessario.
- Verifica PHP e rendering dei casi misto, solo gerarchia, solo calendario e vuoto; intestazioni uniche e conservazione di persone, date e note.


## 2026-10-13 — Smart working soltanto nell'oggetto del riepilogo

- Su richiesta dell'utente dopo la revisione dell'anteprima, ripristinato il layout precedente: BASE con Dipendente, Periodo, Oggetto (32/36/32); HR con Dipendente, Motivo, Periodo, Oggetto (25/23/29/23).
- Nessuna colonna Disponibilità, etichetta di reperibilità o nota sulla contattabilità. Eliminata anche la lettura aggiuntiva di disturbabile dalla query.
- Per SMART, la colonna Oggetto mostra Smart working; l'eventuale oggetto già inserito viene conservato dopo il prefisso. Se contiene già smart working, anche senza spazio o con maiuscole, non viene duplicato. Il valore è escapato come tutti gli altri oggetti.
- Nel riepilogo HR il Motivo della sola riga SMART è un trattino: smart working compare soltanto nell'Oggetto. I motivi delle altre tipologie mantengono le regole precedenti.
- Oggetti delle altre causali, privacy personale e mascheratura del congedo tutelato nel BASE restano invariati. Date, orari, font e cornice comune conservati.
- Destinatari, livelli BASE/HR configurati, subject, cron delle 09:00 Europe/Rome, BCC admin, log/idempotenza e aggiornamenti della stessa giornata invariati. BASE continua a contenere solo APPROVATA, HR anche IN_ATTESA; una sola versione per destinatario.
- Solo includes/hr_riepilogo_assenze.php da sostituire sul sito. Nessuno script SQL necessario.
- Verificati sintassi PHP e rendering BASE/HR con oggetto vuoto, valorizzato, già esplicito ed escapato, numero colonne, motivi, orari, privacy del congedo e riepilogo vuoto. Nessuna email reale inviata.


## 2026-10-13 — Allattamento programmato e gestione riservata a HR/admin

- Superata la precedente possibilità di inserimento autonomo Allattamento: causale e gestione singola riservate a hr_responsabile_personale e amministratore globale. Dropdown filtrato e controlli POST; gli altri utenti conservano consultazione e diritti sulle altre causali.
- Nuova pagina allattamento_programmato.php, raggiungibile dai Benefici/permessi tutelati. Eredita lettura di Benefici e Assenze; scrittura richiede entrambi i permessi e ruolo HR/admin, rispettando Visualizza come. Nessun nuovo diritto assegnato tramite migration.
- Programmazione dal/al inclusivi, giorni settimanali, fino a quattro fasce tra 08:00 e 17:00 a intervalli di 15 minuti, massimo due ore complessive al giorno e validità interamente nel beneficio attivo completo. Default lun–ven, 08–09 e 16–17, termine proposto dal beneficio. Chiusure attive registrate escluse automaticamente.
- Anteprima distingue inserimenti, giorni esclusi, fasce identiche già presenti, conflitti e annullamenti. Duplicati approvati/in attesa saltati senza acquisirli nella serie. Conflitti di altre richieste e superamento plafond bloccano la conferma.
- Conferma transazionale dopo mutex pagina.assenze, rilettura dei dati e confronto dell’impronta dell’anteprima; token CSRF e anteprima monouso scadono, impedendo doppi invii e conferme superate. Regole comuni su beneficio, chiusure e plafond ricontrollate sull’intero insieme delle fasce prima degli inserimenti.
- Richieste ordinarie già approvate, una per fascia, senza nuova fonte duplicata di eventi. Le tre nuove tabelle conservano configurazione, soli legami alle richieste e storico delle operazioni. Calendario, ICS, privacy, riepiloghi e registrazioni ordinarie restano basati sulle tabelle precedenti.
- Gestione: modifica del programma dalla decorrenza in avanti, annullamento fascia/giornata/intervallo, interruzione anticipata e annullamento collettivo delle sole fasce della serie in nuove chiusure. Le operazioni della serie non toccano il passato; richieste annullate e audit preservati. La modifica ricrea tutta la parte futura indicata, comprese precedenti eccezioni: anteprima esplicita.
- Il conteggio delle fasce future in chiusura segnala i programmi da verificare; pagina Chiusure offre il collegamento. Nessuna cancellazione automatica. Richieste manuali o di altre serie non vengono modificate. Annullamento consentito anche dopo revoca del diritto o disattivazione del dipendente.
- Una sola notifica web e una sola email complessiva al dipendente per operazione confermata, tramite helper/layout/configurazioni precedenti e personale verificata. Estensione opzionale al helper email limitata all’evento ALLATTAMENTO_PROGRAMMA: le chiamate precedenti mantengono il comportamento. Aggiornamento del riepilogo odierno chiamato una sola volta dopo commit se cambia oggi; nessun ciclo di email per fascia.
- Anche l’annullamento singolo in Assenze acquisisce il mutex prima della lettura decisionale, serializzandolo con programmazione, chiusure e assegnazioni.
- Script ripetibile sql/2026-10-13_allattamento_programmato.sql da eseguire prima dei sei PHP indicati in docs/aggiornamento-2026-10-13-allattamento-programmato.md. Nessuna richiesta effettiva o beneficio personale inserito automaticamente.
- Verificati 282 controlli di servizio/transazioni/recapiti, 42 controlli della pagina e 96 scenari di regressione. Migration eseguita due volte con MariaDB bootstrap. Nessuna email reale o scrittura sul database del sito; concorrenza del server di destinazione non simulata.

