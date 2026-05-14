# Email HR

Questo pacchetto prepara soltanto l'helper email HR.

## Cosa contiene

- `includes/hr_email.php`

## Cosa NON contiene

- Nessuna modifica SQL
- Nessuna modifica a `assenze.php`
- Nessuna modifica a `approvazioni_assenze.php`
- Nessun invio email automatico

## Configurazioni usate

L'helper usa solo configurazioni gia presenti nel database:

- `HR_NOTIFICA_EMAIL_ATTIVA`
- `HR_EMAIL_FROM`
- `HR_EMAIL_FROM_NAME`
- `HR_EMAIL_MITTENTE`
- `HR_EMAIL_NOME_MITTENTE`
- `HR_URL_PORTALE`

## Logica destinatari

Quando verra collegato ai flussi HR, l'helper cerchera l'email in questo ordine:

1. recapiti HR attivi:
   - `EMAIL_LAVORO`
   - `EMAIL_PERSONALE`
2. campo `aut_utenti.email`

## Nota operativa

Questo step e' volutamente prudente: introduce l'infrastruttura, ma non la collega ancora al workflow richieste/approvazioni.
