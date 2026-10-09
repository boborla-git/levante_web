# Chiusure aziendali e allattamento

`python3 tests/chiusure-allattamento/run_tests.py` esegue i PHP reali con una fixture PDO, sessione e notifiche sostituite. Richiede PHP 8 con PDO; `PHP_BIN` e `PHP_LIB_DIR` sono opzionali. Nessuna email viene inviata. Copre validità inclusiva, limite cumulato, stati, indipendenza utenti/date/causali, chiusure, ruoli, CSRF, sola lettura, benefici, riclassificazione e approvazioni.

`test_sql.py` richiede un datadir **isolato e già inizializzato**, destinato esclusivamente ai test, e le variabili `MARIADB_BIN`, `MARIADB_BASEDIR`, `MARIADB_TEST_DATADIR`. Esegue MariaDB in modalità bootstrap, senza network/socket. Crea solo il database di test `levante_regole_test`; non eseguirlo su un datadir di produzione. Copre migration ripetibile, permessi, conservazione dati e query di conteggio estratte dal PHP di produzione.

`test_ui.py` richiede anche Node.js e verifica il JavaScript renderizzato dal modulo Assenze: modalità Ore automatica per Allattamento, ritorno alle altre modalità, blocco degli intervalli che attraversano una chiusura. Non sostituisce la verifica visiva su browser/smartphone.

La fixture non verifica l'effettiva serializzazione fra connessioni DB simultanee: verifica l'acquisizione del lock transazionale prima dei controlli e l'uso dello stesso servizio nei flussi reali.
