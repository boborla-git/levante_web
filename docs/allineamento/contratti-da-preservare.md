# Contratti da preservare

## HR assenze

- Richieste a giorni: `Al giorno` precompilato con `Dal giorno` se vuoto.
- Richieste a ore: `Al giorno` sincronizzato con `Dal giorno`.
- Orari a step di 5 minuti.
- `Alle ore` proposto a `Dalle ore + 1h`.
- Blocco retrodatate dove previsto.
- Controllo sovrapposizioni distinto per giorni e ore.
- Annullamento richiesta con storico, notifiche e email dopo commit.

## Approvazioni

- Rifiuto con motivazione obbligatoria.
- Approvazione consentita senza nota.
- Protezione doppio submit lato client e controllo transazionale lato server.
- Email/notifiche dopo commit, errori email non bloccanti.

## Calendario

- Gerarchia visibile solo al primo livello, non ricorsiva.
- Gruppi come relazione tra pari, non gerarchica.
- Privacy tipologie secondo configurazione e permessi.
- Pendenti visibili solo a richiedente, approvatore o chi ha permesso globale.

## Email HR

- Template HTML golden master: intestazione, saluto, tabella dettaglio, badge stato, CTA, footer con codice richiesta, sezione assenze presenti per approvatore.
- Invio reale solo con `HR_NOTIFICA_EMAIL_ATTIVA = 1` e `HR_EMAIL_WORKFLOW_ATTIVO = 1`.

## Permessi

- Admin super-user globale.
- Permessi atomici/componibili, nessun hardcoding per nomi utente.
- Giorgia HR e Stefano Direzione restano ruoli distinti.
