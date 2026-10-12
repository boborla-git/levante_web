# Coerenza permessi ruoli

Eseguire `python3 tests/permessi-ruoli/run_tests.py` con PHP 8/PDO disponibile. `PHP_BIN` e `PHP_LIB_DIR` sono opzionali.

La suite usa il PHP di produzione e le funzioni reali di autorizzazione con PDO sostituito: confronta 162 combinazioni read/write/view/edit per pagine e azioni, riproduce la configurazione discordante view/edit consentiti ma read/write negati, verifica i tre livelli di salvataggio, il flag Menu indipendente, i contenitori puri, i dinieghi delle azioni riservate, il mancato aggiornamento degli altri ruoli e il rollback.

Non accede al database del sito. I ruoli e gli ID sono fixture di prova; non attestano il contenuto attuale del database di produzione.
