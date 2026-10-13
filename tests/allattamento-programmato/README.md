# Verifica allattamento programmato

Richiede PHP 8.1+ con PDO e PDO SQLite, e Python 3. Nessuna libreria esterna, rete, database del sito o email reale.

```sh
php test.php
python3 test-pages.py
```

Eseguire da questa cartella. `PHP_CMD` permette di indicare il comando PHP e le opzioni da usare per il secondo comando, ad esempio `PHP_CMD='php -d extension=pdo_sqlite'`.

La fixture SQLite esegue le query del servizio, le transazioni e i rollback reali; rimuove soltanto `FOR UPDATE` dal lock della risorsa e registra le funzioni temporali MySQL. Per costruire le tre nuove tabelle adatta la sintassi DDL della stessa migrazione di produzione. Le verifiche della pagina sostituiscono autenticazione, cornice HTML e invio delle notifiche, poi eseguono il PHP della pagina.

La migrazione originale va verificata anche con MySQL/MariaDB. Per questa consegna è stata eseguita due volte con MariaDB 10.11 in modalità bootstrap, con tabelle padre aventi gli stessi tipi delle chiavi e inserimenti validi nelle tre tabelle. Non si avvia un server durante questi test.
