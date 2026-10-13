# Test visibilità calendario

Eseguire `php tests/visibilita-calendario/test.php` con PDO disponibile. Usa i servizi organizzativi, lo scope calendario, lo scope gestionale e la presentazione ICS reali, con PDO sostituito. Non accede al database del sito.

Copre assegnazione separata dal responsabile, più referenti, idempotenza, sovrapposizioni, date, utenti inattivi, revoca, direzione A→B, nessuna transitività, nessun perimetro approvativo/gestionale aggiuntivo, assenze personali mascherate e dettagli di lavoro web/ICS. La fixture di base proviene dai test dei profili dipendenti.
