# Template email HR bulletproof

Questo pacchetto consolida il template email HR come golden master tecnico.

## Obiettivo

Rendere il rendering piu stabile su:

- Outlook desktop
- Aruba Webmail
- Gmail / browser webmail

## Correzioni principali

- layout basato su tabelle email-safe
- stili inline su ogni cella critica
- font-family esplicito su tabelle, righe, celle e CTA
- colonne dettagli a larghezza fissa
- badge stato inline
- pulsante CTA come tabella bulletproof
- sezione approvatore allineata in tabella
- `Content-Type: text/html; charset=UTF-8`

## Contratti consolidati

Le email HR devono mantenere:

- intestazione `Portale HR Ravioli S.p.A.`
- saluto personalizzato
- messaggio principale
- sezione `Dettaglio richiesta`
- tabella dettagli allineata
- badge stato
- CTA `Apri richiesta nel portale`
- footer con codice richiesta
- sezione `Assenze/richieste già presenti nel periodo` per le email al responsabile

## File modificati

- `includes/hr_email.php`
- `includes/hr_notifiche.php`

## Nota

Nessuna modifica SQL.
Nessuna modifica al workflow HR.
