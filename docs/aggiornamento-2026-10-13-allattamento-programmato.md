# Allattamento programmato — 13 ottobre 2026

## Installazione

1. Eseguire sul database del portale `sql/2026-10-13_allattamento_programmato.sql`. È ripetibile e crea tre tabelle per programmi, collegamenti alle richieste e storico. Non inserisce richieste, utenti o benefici; non modifica i permessi.
2. Caricare nella cartella principale del sito i file completi `allattamento_programmato.php`, `assenze.php`, `benefici_hr.php`, `chiusure_aziendali.php`.
3. Caricare nella cartella `includes/` i file completi `hr_allattamento_programmato.php` e `hr_notifiche.php`.

Gli altri file presenti nel commit sono documentazione e test: non vanno caricati sul sito. Non serve modificare cron o token ICS.

## Utilizzo

Aprire la pagina **Benefici e diritti HR / permessi tutelati** e premere **Allattamento programmato**. Accesso riservato al ruolo `hr_responsabile_personale` e all’amministratore globale. Per modificare servono anche i permessi di scrittura sulle pagine Benefici e Assenze; i profili con sola lettura non possono inviare operazioni.

1. Assegnare prima al dipendente un diritto Allattamento attivo con data iniziale e finale.
2. Nella nuova pagina scegliere il dipendente, il periodo compreso nella validità del diritto, i giorni della settimana e le fasce orarie. Sono preimpostati lunedì–venerdì, 08:00–09:00 e 16:00–17:00; il termine viene proposto dal diritto assegnato e va verificato prima di confermare.
3. Premere **Mostra anteprima**: controllare fasce da inserire, fasce già presenti, giorni esclusi per chiusura ed eventuali conflitti. Sono escluse le chiusure attive registrate nel portale; non vengono inventate ulteriori festività o chiusure.
4. Premere **Conferma**. Tutta l’operazione è transazionale: se un controllo fallisce non viene salvata una parte della programmazione. Se richieste, diritto o chiusure cambiano dopo l’anteprima, viene richiesta una nuova anteprima.

Per l’esempio 13/10/2026–06/03/2027, il 6 marzo è sabato e l’ultima giornata selezionata è venerdì 5 marzo. Ci sono 104 giornate lunedì–venerdì prima delle chiusure, quindi 208 fasce prima delle esclusioni.

Gli inserimenti sono richieste normali già approvate da HR/admin, una per fascia, collegate alla serie. Calendario, ICS, riepiloghi, profilo dipendente e storico continuano a utilizzare le richieste e i periodi esistenti. La causale Allattamento è nascosta ai dipendenti e agli altri responsabili; anche POST manuali e annullamenti non HR sono rifiutati. L’utente continua a consultare le proprie registrazioni.

## Modifiche e annullamenti

- **Apri programma**: cambiare decorrenza, termine, giorni o fasce, poi anteprima e conferma. Vengono annullate solo le richieste attive di quel programma dalla decorrenza in avanti, anche dopo il nuovo termine se si accorcia il programma; vengono inserite le nuove fasce. Il periodo precedente e tutte le richieste annullate restano nello storico.
- **Annulla fascia**: anteprima e annullamento di una sola fascia. Per spostarla utilizzare la modifica del programma dalla data scelta o l’inserimento singolo HR già disponibile.
- **Annulla una giornata**: indicare lo stesso giorno nei due campi e verificare l’anteprima.
- **Annulla fasce nel periodo / interrompi programma**: indicare l’intervallo da annullare; per interrompere anticipatamente, dalla data scelta fino al termine del programma.
- **Annulla fasce nei giorni di chiusura aziendale**: controllare l’intervallo e confermare l’annullamento delle sole fasce del programma ricadenti in chiusure attive. La lista programmi mostra il numero di fasce future in chiusura e la scheda le segnala. La pagina Chiusure rimanda alla funzione. L’annullamento non è automatico e non tocca richieste di altri programmi o inserimenti manuali.

Le operazioni della serie interessano soltanto oggi e il futuro. Per correzioni storiche HR/admin mantengono la gestione singola precedente. Un successivo comando di modifica sostituisce tutta la parte futura selezionata, comprese eventuali eccezioni annullate in precedenza: l’anteprima elenca tutto prima del salvataggio. L’annullamento è possibile anche dopo la revoca del diritto o la disattivazione del dipendente; nuove programmazioni e modifiche che inseriscono fasce richiedono dipendente e diritto attivi.

Le fasce identiche già approvate o in attesa vengono mostrate come già presenti e non vengono duplicate né acquisite nella nuova serie. Altri conflitti e superamenti delle due ore bloccano l’intero inserimento. Orari fra le 08:00 e le 17:00 a intervalli di 15 minuti, massimo quattro fasce e massimo due ore complessive al giorno, periodo massimo due anni per singola operazione.

## Comunicazioni

Una sola notifica nel portale e una sola comunicazione email complessiva al dipendente per ogni operazione confermata, con periodo, giorni, fasce e conteggi. Il trasporto e il layout sono quelli comuni del portale; viene usato soltanto il recapito personale verificato, nel rispetto della configurazione workflow esistente. Se manca il recapito o l’email è disattivata, le richieste rimangono salvate e la notifica consultabile nel portale. Gli esiti sono registrati dal helper notifiche esistente.

Se cambia oggi e il riepilogo del mattino è già stato inviato, il portale chiama una sola volta l’aggiornamento consolidato del riepilogo (con i livelli BASE/HR precedenti), dopo che tutte le fasce sono state aggiornate. Le programmazioni solo future non generano aggiornamenti del riepilogo odierno. Non viene inviata una comunicazione per ogni richiesta.

## Verifiche

- Sintassi PHP di tutti i sei file applicativi.
- 282 controlli su pianificatore e servizio, con transazioni e rollback reali SQLite, funzioni temporali MySQL adattate e stessa logica PHP di produzione: validità, limite giornaliero, conflitti, stati, duplicati, modifica futura, chiusure, annullamenti, revoche, dipendente disattivato e recapiti email. Il trasporto email è sostituito: nessun invio reale.
- 42 controlli sulla pagina PHP eseguita: accessi, sola lettura, anteprima senza scritture, conferma, notifiche consolidate, scadenza e consumo token, CSRF, impersonazione, anteprima superata e nuove chiusure.
- 96 scenari di regressione su regole, pagina Assenze, assegnazione benefici, chiusure e approvazioni, inclusi Congedo, rimozione Altro e blocco dell’allattamento non HR.
- Migrazione eseguita due volte con MariaDB 10.11 in modalità bootstrap e verifica degli inserimenti nelle tre tabelle e dei relativi vincoli.

Non sono stati eseguiti cambiamenti sul database del sito né invii reali. Le prove transazionali SQLite e il controllo del lock comune non sostituiscono una prova di concorrenza sul server Aruba; il percorso di conferma acquisisce comunque lo stesso mutex delle chiusure e dei diritti prima della rilettura e della scrittura.
