# Test calendario chiusure e login

Eseguire `python3 tests/calendario-chiusure-login/run_tests.py`, con PHP 8/PDO e Node.js disponibili. `PHP_BIN` e `PHP_LIB_DIR` permettono di specificare un runtime PHP alternativo.

La pagina Calendario assenze e gli helper condivisi sono quelli reali del repository. PDO, sessione e layout sono sostituiti; non si accede al database del sito. La suite verifica viste, estremi inclusivi, chiusure disattivate, scope, privacy, pendenti, richieste preesistenti e descrizioni contenenti markup. Esegue anche il JavaScript effettivo dei popup e del pulsante Password in un DOM simulato, verificando testo/cursore, tastiera, invio e riapertura. Non è una prova visiva su dispositivi reali.
