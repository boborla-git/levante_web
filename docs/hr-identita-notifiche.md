# HR Enterprise hardening - identita utente e notifiche parlanti

## Obiettivo

Questo pacchetto applica due rifiniture operative senza modificare workflow HR, email o database.

## Modifiche

- Nel menu alto il gruppo `Profilo` mostra il nome e cognome dell'utente loggato, mantenendo badge notifiche e dropdown esistente.
- Il centro notifiche torna piu parlante: per ogni notifica HR collegata a una richiesta mostra anche:
  - richiedente
  - tipologia
  - periodo
  - stato
  - oggetto

## Contratti preservati

- apertura notifica = segna come letta
- segna singola notifica come letta
- segna tutte come lette
- badge notifiche nel menu
- notifiche web esistenti
- workflow richieste/approvazioni
- email HR golden master

## Nota permessi

Admin resta super-user globale. I permessi atomici HR introdotti nel commit precedente servono per utenti specifici, ma non devono limitare l'admin.
