# Primo collegamento email workflow HR

Questo pacchetto collega in modo prudente l'invio email al flusso di `assenze.php`.

## Eventi collegati

- Nuova richiesta in attesa: email al responsabile approvatore e conferma all'utente richiedente.
- Richiesta inserita senza autorizzazione/responsabile: email di conferma solo all'utente.
- Richiesta annullata: email agli stessi destinatari gia previsti dalla notifica web.

## Sicurezza

Gli invii reali restano bloccati finche `HR_EMAIL_WORKFLOW_ATTIVO` resta a `0`.

Le email vengono accodate durante la transazione e inviate solo dopo il commit della richiesta, cosi non partono email per operazioni non confermate nel database.
