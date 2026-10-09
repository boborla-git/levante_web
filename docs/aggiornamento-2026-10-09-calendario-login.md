# Calendario chiusure e pulsante password — 9 ottobre 2026

Caricare nella cartella principale del sito, accanto a `index.php`, i file completi:

- `calendario_assenze.php`
- `login.php`

Non serve SQL aggiuntivo. La tabella delle chiusure è quella già creata nell'aggiornamento precedente. Nessun CSS o altro file applicativo da caricare; gli stili specifici sono contenuti nelle due pagine. Ricaricare le pagine già aperte dopo la sostituzione.

Il calendario mostra le chiusure attive con grigio tenue, quadratino, legenda, riga aziendale e riepilogo delle date nelle viste Giorno/2 settimane/Mese. Le richieste individuali già presenti restano visibili. La riga aziendale compare anche se il filtro non mostra persone, senza modificarne il perimetro o i permessi. Il dettaglio è disponibile con clic, tocco e tastiera.

Nel login l'occhio dentro il campo Password permette di vedere o nascondere i caratteri inseriti. Il valore rimane uguale; il pulsante non invia il modulo. La password torna nascosta all'invio e alla riapertura della pagina.

Su GitHub sono aggiornati anche `docs/contratti-funzionali-hr.md` e i test in `tests/calendario-chiusure-login/`: non occorre caricare queste cartelle sul sito.

Verifiche: PHP 8.3; 19 scenari su pagina/helper reali con PDO sostituito; JavaScript dei popup e della password eseguito con DOM simulato; confronto del blocco PHP di autenticazione, che rimane invariato. Non sono state eseguite operazioni sul database del sito o prove visive in un browser reale.
