# Reparti e centri di costo

Da Profili dipendenti aprire Reparti oppure Centri di costo. Inserire codice e nome e premere Crea. La nuova voce è attiva e compare subito nelle tendine dei profili; per assegnarla a un dipendente selezionarla e salvare il profilo.

Le pagine condividono i permessi di lettura e scrittura di profili_dipendenti. Amministratori globali mantengono il loro accesso. La modalità Visualizza come non può effettuare scritture.

Questa versione aggiunge creazione e consultazione, senza modifica o eliminazione delle voci esistenti. La creazione non altera assegnazioni, utenti, password o ruoli. Non richiede migrazioni SQL: usa hr_reparti e hr_centri_costo esistenti e legge dal database le lunghezze consentite per codice e nome.

Codici normalizzati in maiuscolo; duplicati respinti anche tra voci inattive. I salvataggi sono protetti da token CSRF e serializzati per tabella con GET_LOCK, con transazione e rollback in caso di errore. I nomi e i codici sono escapati nell'HTML.

Verifica: lint dei cinque file PHP; test con PDO simulato per entrambe le anagrafiche (creazione, duplicati, voce inattiva, rollback, lock occupato, limiti e UTF-8); dodici scenari delle pagine (lettura, sola lettura, CSRF errato, scrittura negata, impersonazione, creazione). Eseguire python tests/hr-anagrafiche/run_tests.py con PHP CLI/PDO e Python lxml. Non sono stati eseguiti inserimenti sul database di produzione.
