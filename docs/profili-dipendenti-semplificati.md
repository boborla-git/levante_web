# Profili dipendenti: consultazione e gestione

## Interfaccia

Una tabella con una riga per persona sostituisce le schede ripetute. Filtri per testo, reparto, centro di costo e stato. Apri profilo mostra un solo modulo completo. Matricola, mansione, reparto, centro di costo, responsabile funzionale, assunzione, cessazione, note HR e stato del profilo sono conservati. Account e profilo HR hanno stati distinti. Account disattivi restano consultabili senza modifiche. Profili HR disattivi di account attivi possono essere riattivati da chi ha il permesso di scrittura già previsto.

Le pagine e gli export mostrano solo responsabilità e appartenenze ai team valide alla data odierna. Relazioni organizzative mantiene anche lo storico e le assegnazioni pianificate. Le pagine conservano i permessi preesistenti: profili_dipendenti per i profili, configurazione_assenze per le relazioni. Nessun nuovo permesso o migrazione SQL.

Aprire Profili dipendenti non scrive più nel database. I profili eventualmente mancanti vengono creati soltanto tramite il pulsante esplicito Crea profili mancanti, protetto da permessi e CSRF.

## Dati e responsabilità

Nome, cognome e username rimangono in aut_utenti. Dati HR e riferimenti a reparto/centro rimangono in hr_profili_dipendenti. Responsabilità rimangono in hr_relazioni_organizzative; team in hr_gruppi_utenti. Nessuna copia di codice/nome di reparto o centro nel profilo.

Le due pagine usano includes/hr_organizzazione.php. Il cambio vale dalla data scelta; la relazione precedente termina il giorno prima senza eliminazioni né sostituzioni delle sue note. Una sostituzione nello stesso giorno disattiva la riga precedente senza generare date negative. Una responsabilità pianificata in conflitto blocca l'intero salvataggio: nessun cambio parziale del profilo. Il responsabile invariato conserva durata e pianificazioni esistenti. Una sostituzione con fine esplicita conserva, dopo la scadenza, il responsabile già previsto; eventuali pianificazioni successive hanno precedenza. Un periodo con più responsabilità sovrapposte deve essere verificato prima di inserire una sostituzione a termine.

Transazioni e lock sul dipendente serializzano salvataggi e chiusure. Le responsabilità pregresse multiple non vengono ripulite all'apertura: la scheda avvisa e il salvataggio esplicito normalizza il periodo da oggi. Le assegnazioni storiche non vengono migrate da questo pacchetto.

## Verifica

Lint dei cinque file PHP. Test con PDO simulato per storico, periodi finiti e futuri, cambio nello stesso giorno, conflitto pianificato, responsabile invariato, normalizzazione dei due tipi, rimozione senza DELETE, rollback, date/utenti/tipo e CSRF. Ventitré scenari delle due pagine: GET senza scritture, selezione singola, filtri, sola lettura, stati separati, impersonazione, salvataggi, creazione esplicita, errori e chiusura. Esportazioni allineate al filtro data_inizio. Controllati i consumatori operativi (assenze, calendario, scope): già filtrano inizio/fine dei periodi. Nessun inserimento sul database di produzione; anteprima browser locale non disponibile.

Esecuzione: Python 3 e PHP CLI con PDO; python tests/profili-dipendenti/run_tests.py. PHP_BINARY e PHP_TEST_ARGS permettono un runtime personalizzato.
