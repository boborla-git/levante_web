# Email HR su approvazioni e rifiuti

Questo pacchetto collega l'invio email agli esiti di approvazione/rifiuto.

## File modificato

- `approvazioni_assenze.php`

## Comportamento

Dopo il commit della transazione DB:

- approvazione -> email al richiedente
- rifiuto -> email al richiedente con motivazione

## Sicurezza

L'invio reale resta bloccato finche' nel database:

```text
HR_EMAIL_WORKFLOW_ATTIVO = 0
```

La funzione `hrCreaNotificaEmailPerUtenti()` invia davvero solo con:

```text
HR_NOTIFICA_EMAIL_ATTIVA = 1
HR_EMAIL_WORKFLOW_ATTIVO = 1
```

## Contratti preservati

- notifiche web gia esistenti
- redirect con filtri
- transazione approvazione/rifiuto
- protezione doppio submit
- workflow approvativo gia consolidato
