# Notifiche email HR

Questo pacchetto prepara la funzione centralizzata:

- `hrCreaNotificaEmailPerUtenti(...)`

## Scelta prudente

La funzione e' disponibile ma NON viene ancora richiamata automaticamente da:

- `assenze.php`
- `approvazioni_assenze.php`

Questo evita invii email involontari, soprattutto perche' nel database reale `HR_NOTIFICA_EMAIL_ATTIVA` risulta gia' attiva.

## Prossimo passo

Quando decidiamo di attivare il primo evento email, conviene partire da un solo caso controllato, per esempio:

- email al responsabile quando arriva una nuova richiesta da approvare

Solo dopo test positivo estenderemo a:

- email al richiedente per approvazione
- email al richiedente per rifiuto
- email per annullamento
