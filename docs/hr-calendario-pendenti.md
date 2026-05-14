# Calendario HR - richieste pendenti

Questo pacchetto corregge la vista calendario per includere le richieste `IN_ATTESA` dove previsto.

## File modificato

- `calendario_assenze.php`

## Comportamento

Il calendario mostra:

- richieste `APPROVATA`
- richieste `IN_ATTESA` se:
  - appartengono all'utente loggato
  - sono assegnate all'utente loggato come approvatore
  - l'utente ha il permesso atomico `azione.hr.assenze.visualizza_pendenti_globali`

## Privacy

- Gli utenti normali continuano a vedere il dettaglio solo quando autorizzati dalle regole esistenti.
- HR/Direzione usano i permessi atomici:
  - `azione.hr.assenze.visualizza_tutte`
  - `azione.hr.assenze.visualizza_tipologie`
  - `azione.hr.assenze.visualizza_pendenti_globali`
- Admin resta super-user globale tramite i permessi già presenti.

## Contratti preservati

- Layout calendario
- Pannello dettaglio giorno
- Navigazione giorno/mese
- Privacy utenti normali
- Email HR golden master
- Notifiche web
- Workflow approvazioni
