# Ripristino template HTML email HR

Questo pacchetto ripristina il template HTML consolidato delle email HR.

## File modificati

- `includes/hr_email.php`
- `includes/hr_notifiche.php`

## Contratto ripristinato

Le email HR tornano a usare il layout approvato:

- intestazione `Portale HR Ravioli S.p.A.`
- saluto personalizzato
- dettaglio richiesta in tabella
- badge stato
- pulsante `Apri richiesta nel portale`
- footer con codice richiesta
- per le richieste da approvare: se presenti, sezione `Assenze/richieste già presenti nel periodo`

## Cosa non cambia

- nessuna modifica SQL
- nessuna modifica a `assenze.php`
- nessuna modifica a `approvazioni_assenze.php`
- nessuna modifica a calendario/notifiche web
- resta attivo il controllo `HR_EMAIL_WORKFLOW_ATTIVO`

## Nota metodologica

Il template email HTML HR va considerato golden master: futuri interventi su email/notifiche non devono degradarlo a testo semplice o layout minimale.
