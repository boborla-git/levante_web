# Database — inventario e verifica di utilizzo

Aggiornamento: **17 ottobre 2026**. Questo documento aggiorna il precedente inventario basato sul dump del 15 maggio; non introduce una seconda versione delle regole.

## Fonti e limiti

- Codice ufficiale: `boborla-git/levante_web`, branch `main`, commit analizzato `c34067c92085cd0e8cd70248bd635396c6452a05`.
- Esaminati tutti i **68 file PHP applicativi** del repository, compresi include, cron e API; esclusi i test. Le copie analizzate sono state verificate tramite hash Git.
- Ultimo dump reperito fra i file condivisi del progetto: `Sql1931055_1 (2)(1).sql`, generato il **29 settembre 2026 alle 05:10** (data riportata nell'intestazione). L'orario non è stato convertito perché il dump non specifica il fuso dell'intestazione. Server MySQL 8.0.45-36.
- Il dump contiene **41 tabelle fisiche, 2 viste e 1 trigger**. Le due istruzioni CREATE TABLE temporanee di appoggio alle viste, emesse da phpMyAdmin, non sono altre due tabelle persistenti.
- È un'analisi statica dei sorgenti e del dump, non una misura delle query effettivamente eseguite in produzione. Non certifica quali file siano stati caricati sul server né lo stato attuale del database.
- Le aggiunte successive al dump sono documentate separatamente: la loro presenza in produzione richiede un dump aggiornato. Non sono state importate né modificate informazioni del database reale.

## Risultato

Tutte le **41 tabelle fisiche** hanno riferimenti applicativi nei PHP correnti. Non è stata identificata una tabella fisica del dump completamente priva di codice di supporto. Questo risultato non dimostra che la struttura sia ottimale o che ogni modulo sia usato quotidianamente.

Due tabelle appartengono a funzionalità sospese o disattivate:

- `hr_timbrature`: il codice esiste nella home, ma `$mostraStatoPresenza = false` ne impedisce attualmente la visualizzazione e l'esecuzione dei flussi. Il dump contiene dati: non va eliminata come se fosse una tabella vuota.
- `hr_richieste_iban`: il codice esiste in `cambio_iban.php`, che controlla l'attivazione della risorsa prima di accedere alle richieste. Nel dump `pagina.cambio_iban` ha `attivo = 0` e la tabella non contiene INSERT di dati. L'attivazione odierna va verificata su un dump nuovo.

Le restanti 39 tabelle hanno riferimenti nei percorsi applicativi non esclusi da queste due disattivazioni; il loro uso effettivo dipende anche da ruoli, configurazioni e azioni degli utenti.

La vista `v_hr_email_log` non è richiamata da nessuno dei 68 PHP. È una vista diagnostica sulle notifiche email, non una copia dei dati. La vista `v_hr_profili_dipendenti` è invece letta dalle pagine dei profili e dagli export.

## Inventario delle tabelle del dump

I riferimenti riportati sono esempi verificati, non l'elenco completo di tutti i punti di utilizzo. Una funzione centralizzata può servire numerose pagine.


| Tabella | Funzione | Riferimenti applicativi |
| --- | --- | --- |
| `aut_log_accessi` | Tracciamento accessi e autorizzazioni. | [includes/autorizzazioni.php](../../includes/autorizzazioni.php) |
| `aut_risorse` | Pagine, azioni, menu e risorse autorizzabili. | [permessi_ruoli.php](../../permessi_ruoli.php), [cambio_iban.php](../../cambio_iban.php), [login.php](../../login.php) |
| `aut_ruoli` | Anagrafica dei ruoli. | [permessi_ruoli.php](../../permessi_ruoli.php), [utenti.php](../../utenti.php), [visualizza_come.php](../../visualizza_come.php) |
| `aut_ruoli_permessi` | Permessi assegnati ai ruoli. | [permessi_ruoli.php](../../permessi_ruoli.php), [login.php](../../login.php), [includes/layout.php](../../includes/layout.php) |
| `aut_tipi_utente` | Tipologie utente, utilizzate nella creazione degli utenti. | [utente_nuovo.php](../../utente_nuovo.php) |
| `aut_utenti` | Identità e autenticazione degli utenti. | [utenti.php](../../utenti.php), [export_profili_dipendenti.php](../../export_profili_dipendenti.php), [gruppi_lavoro.php](../../gruppi_lavoro.php) |
| `aut_utenti_ruoli` | Assegnazioni dei ruoli agli utenti. | [utenti.php](../../utenti.php), [visualizza_come.php](../../visualizza_come.php), [riepilogo_assenze_email.php](../../riepilogo_assenze_email.php) |
| `fornitori_contatti` | Contatti fornitori e sincronizzazione. | [ordini_fornitori_aperti.php](../../ordini_fornitori_aperti.php), [api/sync/import_fornitori_contatti.php](../../api/sync/import_fornitori_contatti.php) |
| `hr_benefici_utenti` | Diritti individuali e periodi di validità dei permessi tutelati. | [allattamento_programmato.php](../../allattamento_programmato.php), [assenze.php](../../assenze.php), [permessi_104.php](../../permessi_104.php) |
| `hr_canali_notifica` | Canali delle notifiche, inclusi web ed email. | [notifiche.php](../../notifiche.php), [includes/layout.php](../../includes/layout.php), [includes/hr_notifiche.php](../../includes/hr_notifiche.php) |
| `hr_centri_costo` | Anagrafica dei centri di costo. | [profili_dipendenti.php](../../profili_dipendenti.php), [includes/hr_anagrafiche.php](../../includes/hr_anagrafiche.php) |
| `hr_chiusure_mese` | Blocco amministrativo dei mesi già consolidati. | [chiusure_mese.php](../../chiusure_mese.php), [assenze.php](../../assenze.php), [approvazioni_assenze.php](../../approvazioni_assenze.php) |
| `hr_comunicazioni` | Comunicazioni HR. | [hr_comunicazioni.php](../../hr_comunicazioni.php), [cambio_iban.php](../../cambio_iban.php) |
| `hr_comunicazioni_letture` | Registrazione delle letture delle comunicazioni. | [hr_comunicazioni.php](../../hr_comunicazioni.php) |
| `hr_configurazioni` | Impostazioni condivise del modulo HR, email e ICS. | [riepilogo_assenze_email.php](../../riepilogo_assenze_email.php), [configurazione_assenze.php](../../configurazione_assenze.php), [mio_calendario.php](../../mio_calendario.php) |
| `hr_gruppi_lavoro` | Anagrafica dei gruppi di lavoro. | [export_profili_dipendenti.php](../../export_profili_dipendenti.php), [gruppi_lavoro.php](../../gruppi_lavoro.php), [export_personale_responsabile.php](../../export_personale_responsabile.php) |
| `hr_gruppi_utenti` | Appartenenza degli utenti ai gruppi. | [export_profili_dipendenti.php](../../export_profili_dipendenti.php), [gruppi_lavoro.php](../../gruppi_lavoro.php), [export_personale_responsabile.php](../../export_personale_responsabile.php) |
| `hr_notifiche` | Eventi e contenuti delle notifiche. | [allattamento_programmato.php](../../allattamento_programmato.php), [notifiche.php](../../notifiche.php), [assenze.php](../../assenze.php) |
| `hr_notifiche_destinatari` | Destinatari, invio, lettura ed errori delle notifiche. | [notifiche.php](../../notifiche.php), [includes/layout.php](../../includes/layout.php), [includes/hr_notifiche.php](../../includes/hr_notifiche.php) |
| `hr_profili_dipendenti` | Dati anagrafici HR separati dall’autenticazione. | [utenti.php](../../utenti.php), [allattamento_programmato.php](../../allattamento_programmato.php), [utente_nuovo.php](../../utente_nuovo.php) |
| `hr_recapiti_utenti` | Recapiti personali/lavorativi e preferenze. | [configurazione_assenze.php](../../configurazione_assenze.php), [recapiti_utenti.php](../../recapiti_utenti.php), [assenze.php](../../assenze.php) |
| `hr_recapiti_verifiche` | Verifica dei recapiti tramite token. | [verifica_recapito.php](../../verifica_recapito.php), [includes/hr_recapiti.php](../../includes/hr_recapiti.php) |
| `hr_relazioni_organizzative` | Relazioni gerarchiche, funzionali e di visibilità calendario. | [export_profili_dipendenti.php](../../export_profili_dipendenti.php), [export_personale_responsabile.php](../../export_personale_responsabile.php), [configurazione_assenze.php](../../configurazione_assenze.php) |
| `hr_reparti` | Anagrafica dei reparti. | [profili_dipendenti.php](../../profili_dipendenti.php), [includes/hr_anagrafiche.php](../../includes/hr_anagrafiche.php) |
| `hr_richieste` | Testata delle richieste di assenza e relativo stato. | [index.php](../../index.php), [allattamento_programmato.php](../../allattamento_programmato.php), [notifiche.php](../../notifiche.php) |
| `hr_richieste_approvazioni` | Passaggi e assegnazioni delle approvazioni. | [index.php](../../index.php), [assenze.php](../../assenze.php), [report_assenze.php](../../report_assenze.php) |
| `hr_richieste_iban` | Richieste di cambio IBAN; funzione disattivata nel dump. | [cambio_iban.php](../../cambio_iban.php) |
| `hr_richieste_periodi` | Date, fasce orarie e quantità delle richieste. | [allattamento_programmato.php](../../allattamento_programmato.php), [notifiche.php](../../notifiche.php), [assenze.php](../../assenze.php) |
| `hr_richieste_storico` | Storico delle operazioni sulle richieste. | [assenze.php](../../assenze.php), [approvazioni_assenze.php](../../approvazioni_assenze.php), [includes/hr_allattamento_programmato.php](../../includes/hr_allattamento_programmato.php) |
| `hr_riepilogo_assenze_destinatari` | Destinatari configurati dei riepiloghi email. | [riepilogo_assenze_email.php](../../riepilogo_assenze_email.php), [includes/hr_riepilogo_assenze.php](../../includes/hr_riepilogo_assenze.php) |
| `hr_riepilogo_assenze_invi` | Tracciamento dei riepiloghi inviati e controllo degli aggiornamenti. | [riepilogo_assenze_email.php](../../riepilogo_assenze_email.php), [includes/hr_riepilogo_assenze.php](../../includes/hr_riepilogo_assenze.php) |
| `hr_stati_presenza` | Stati di disponibilità associati alle causali. | [configurazione_assenze.php](../../configurazione_assenze.php), [includes/hr_calendario.php](../../includes/hr_calendario.php), [includes/hr_riepilogo_assenze.php](../../includes/hr_riepilogo_assenze.php) |
| `hr_stati_richiesta` | Stati del ciclo di vita delle richieste. | [index.php](../../index.php), [allattamento_programmato.php](../../allattamento_programmato.php), [notifiche.php](../../notifiche.php) |
| `hr_timbrature` | Registrazioni presenza; funzione sospesa nel codice della home. | [index.php](../../index.php) |
| `hr_tipi_recapito` | Tipologie dei recapiti. | [configurazione_assenze.php](../../configurazione_assenze.php), [recapiti_utenti.php](../../recapiti_utenti.php), [assenze.php](../../assenze.php) |
| `hr_tipi_relazione_organizzativa` | Tipologie delle relazioni fra utenti. | [export_profili_dipendenti.php](../../export_profili_dipendenti.php), [export_personale_responsabile.php](../../export_personale_responsabile.php), [utente_nuovo.php](../../utente_nuovo.php) |
| `hr_tipologie_evento` | Causali, modalità ammesse e regole di visibilità. | [configurazione_assenze.php](../../configurazione_assenze.php), [notifiche.php](../../notifiche.php), [assenze.php](../../assenze.php) |
| `ordini_fornitori_aperti` | Ordini fornitori e dati importati. | [index.php](../../index.php), [ordini_fornitori_aperti.php](../../ordini_fornitori_aperti.php), [api/sync/import_ordini_fornitori_aperti.php](../../api/sync/import_ordini_fornitori_aperti.php) |
| `ordini_fornitori_note` | Note correnti degli ordini. | [ordini_fornitori_aperti.php](../../ordini_fornitori_aperti.php) |
| `ordini_fornitori_note_storico` | Storico delle note e scambio con la sincronizzazione. | [ordini_fornitori_aperti.php](../../ordini_fornitori_aperti.php), [api/sync/export_note_ordini_fornitori.php](../../api/sync/export_note_ordini_fornitori.php), [api/sync/ack_note_ordini_fornitori.php](../../api/sync/ack_note_ordini_fornitori.php) |
| `sync_stato` | Esiti e avanzamento della sincronizzazione. | [ordini_fornitori_aperti.php](../../ordini_fornitori_aperti.php), [api/sync/common.php](../../api/sync/common.php) |

Le tabelle senza INSERT di dati nel dump sono `hr_chiusure_mese`, `hr_comunicazioni_letture` e `hr_richieste_iban`. Una tabella vuota non è automaticamente inutile: le prime due sono comunque richiamate dai rispettivi flussi applicativi.

## Viste e controllo automatico nel database

| Oggetto | Uso verificato | Valutazione |
| --- | --- | --- |
| `v_hr_profili_dipendenti` | `profili_dipendenti.php`, `export_profili_dipendenti.php`, `export_personale_responsabile.php` | Vista applicativa da preservare. |
| `v_hr_email_log` | Nessun riferimento nei 68 PHP; aggrega notifiche, destinatari, canale email e utenti. | Strumento diagnostico. Valutare la sua utilità operativa prima di un’eventuale dismissione; non contiene dati duplicati. |
| `trg_hr_104_periodo_bi` | Trigger BEFORE INSERT su `hr_richieste_periodi`; legge benefici, richieste, periodi, stati e causali. | Controlla abilitazione e plafond Legge 104 anche a livello database. Va considerato nelle modifiche alle regole; non è una tabella inutilizzata. |

## Aggiunte successive al dump, presenti negli script e nel codice

| Tabella | Scopo | Fonte strutturale | Codice che la usa |
| --- | --- | --- | --- |
| `hr_ics_token_utenti` | Token personali per la sottoscrizione del calendario. | [SQL del 30 settembre](../../sql/2026-09-30_ics_tutti_utenti.sql) | `mio_calendario.php`; il feed verifica l’hash registrato in `hr_configurazioni`. |
| `hr_chiusure_aziendali` | Periodi di chiusura che bloccano le richieste e compaiono nel calendario. | [SQL del 9 ottobre](../../sql/2026-10-09_chiusure_aziendali_allattamento.sql) | `chiusure_aziendali.php`, `calendario_assenze.php`, regole assenze e programmazione allattamento. |
| `hr_allattamento_programmi` | Configurazione della serie ricorrente. | [SQL del 13 ottobre](../../sql/2026-10-13_allattamento_programmato.sql) | `allattamento_programmato.php`, `includes/hr_allattamento_programmato.php`. |
| `hr_allattamento_programma_richieste` | Collegamento fra programma e normali richieste HR. | Stesso SQL del 13 ottobre. | `includes/hr_allattamento_programmato.php`. |
| `hr_allattamento_programmi_storico` | Tracciamento delle modifiche e degli annullamenti del programma. | Stesso SQL del 13 ottobre. | `includes/hr_allattamento_programmato.php`. |

Queste cinque tabelle non sono nel dump analizzato: non vanno dichiarate assenti dal database attuale. Sono richieste dall’implementazione attuale. La serie di allattamento continua a registrare date, orari e stati nelle normali richieste, senza un secondo archivio parallelo delle assenze.

`hr_chiusure_mese` e `hr_chiusure_aziendali` non sono doppioni: la prima impedisce modifiche amministrative a un mese consolidato; la seconda rappresenta giorni di chiusura dell’azienda.

La Qualifica INPS è una colonna di `hr_profili_dipendenti`, non una nuova tabella. Gli script del 29 settembre includono una prima versione e una migrazione definitiva: non vanno eseguiti indiscriminatamente in blocco, soprattutto dopo la rinomina degli utenti di prova.

## Configurazione dal sito e interventi SQL

La pagina [configurazione_assenze.php](../../configurazione_assenze.php) mostra e salva modalità giorni/ore, approvazione, nome calendario, visibilità e attivazione della causale, ma non espone i tre campi `mostra_dettaglio_colleghi`, `mostra_dettaglio_responsabili`, `mostra_dettaglio_hr`.

La correzione del 16 ottobre per Fiera e Formazione è stata quindi applicata con un UPDATE ai dati già esistenti. Non ha creato tabelle, nuove causali o un secondo sistema di permessi. La casella «colleghi» della pagina non equivale al consenso alla lettura dei dettagli.

Criterio consigliato:

- configurazioni operative ricorrenti di HR/admin: controlli nelle pagine esistenti, con autorizzazioni, validazioni e tracciamento;
- evoluzioni strutturali, migrazioni iniziali e riparazioni eccezionali: script SQL versionati e mirati;
- non rendere modificabile ogni campo tecnico solo perché esiste nel database;
- per le assenze personali, le protezioni già concordate devono prevalere su combinazioni di opzioni incoerenti.

Non è stata modificata la pagina in questo audit: l’estensione della gestione della visibilità va definita e verificata separatamente.

## Interventi consigliati e decisioni ancora aperte

1. Recuperare un dump successivo agli aggiornamenti di ottobre per verificare lo schema effettivamente pubblicato e le impostazioni attuali.
2. Completare la configurazione della visibilità nella pagina esistente, evitando un nuovo pannello scollegato e proteggendo le causali personali.
3. Decidere se mantenere sospese, riattivare o dismettere timbrature e cambio IBAN. Solo dopo una decisione esplicita valutare l’eventuale rimozione di codice, risorse e tabelle, preservando i dati necessari.
4. Tenere un solo inventario aggiornato del database e un registro delle migrazioni applicate. La presenza di un file SQL su GitHub non dimostra che sia stato eseguito sul server.
5. Distinguere nei documenti ciò che è vigente, superato o risolto. Per esempio, `punti-aperti.md` menziona ancora `includes/mail.php` e il link a `workflow.php`, già dichiarati risolti in `chiusura-fase-pulizia-duplicazioni.md`.
6. Prima di nuove strutture, verificare riuso delle tabelle, impatto su pagine/API/cron/viste/trigger, storico e compatibilità. Una ricerca senza riferimenti è un indizio per l’audit, non un’autorizzazione a cancellare.

Non sono state eliminate tabelle o viste, né eseguiti SQL sul database dell’utente. Questo aggiornamento riguarda la documentazione del progetto.
