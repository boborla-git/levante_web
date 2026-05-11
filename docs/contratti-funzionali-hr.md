# LEVANTE WEB - Contratti funzionali HR

Questo documento raccoglie i comportamenti gia' consolidati del modulo HR assenze.
Ogni modifica futura deve preservarli, salvo decisione esplicita contraria.

## Regole operative di sviluppo

1. Prima di ogni modifica verificare la baseline GitHub sulla pagina commit del branch `main`.
2. Se un commit e' stato confermato come eseguito, deve essere considerato presente; se non risulta subito visibile, rileggere/aggiornare GitHub prima di procedere.
3. Ogni pacchetto deve contenere solo file completi realmente modificati.
4. Ogni pacchetto deve avere un commit summary chiaro.
5. Prima di refactoring o pulizia codice verificare i comportamenti gia' approvati della pagina interessata.
6. Non rimuovere logiche locali utili mentre si centralizzano componenti comuni.

## Contratti funzionali - assenze.php

### Date

- Se il tipo richiesta e' `GIORNI`, quando viene compilato `Dal giorno`, il campo `Al giorno` deve essere precompilato con lo stesso valore se vuoto.
- Se il tipo richiesta e' `ORE`, `Al giorno` deve essere sincronizzato con `Dal giorno`.

### Orari

- Per richieste a ore, i campi ora devono lavorare a step di 5 minuti.
- Quando viene compilato `Dalle ore`, il campo `Alle ore` deve proporre automaticamente un orario pari a +1 ora.
- Il controllo sovrapposizioni deve distinguere correttamente richieste a giorni e richieste a ore.
- Le richieste a giorni bloccano l'intero periodo.
- Le richieste a ore bloccano solo se la fascia oraria si sovrappone realmente.

### Validazioni

- Non devono essere accettate richieste retrodatate se la regola applicativa lo vieta.
- Non devono essere accettate sovrapposizioni non consentite.
- I messaggi di errore devono essere chiari e coerenti con gli alert del sito.

### UI

- Il filtro rapido deve usare il comportamento comune centralizzato.
- Badge, pulsanti, alert e campi devono rimanere coerenti col design system.
- Layout desktop/tablet/smartphone deve rimanere ordinato e leggibile.

## Contratti funzionali - approvazioni_assenze.php

- La pagina deve mostrare le richieste coerenti con i filtri impostati.
- Il filtro rapido deve cercare solo nella tabella gia' caricata.
- Il rifiuto deve richiedere una motivazione.
- L'approvazione puo' essere confermata senza nota.
- Badge e stati devono essere coerenti con `assenze.php`.
- La vista deve restare responsive.

## Contratti funzionali - calendario_assenze.php

- Il calendario deve rispettare le regole di visibilita' e privacy delle tipologie evento.
- Le richieste approvate devono essere visibili secondo ruolo, gruppo e relazione organizzativa.
- La visibilita' gerarchica deve fermarsi al primo livello inferiore, non deve essere ricorsiva.

## Contratti dati HR

- La struttura HR deve restare autonoma con tabelle `hr_*`.
- L'integrazione con utenti e permessi deve avvenire tramite `aut_utenti`, `aut_risorse`, `aut_ruoli` e `aut_ruoli_permessi`.
- Non aggiungere campi HR sparsi in `aut_utenti` salvo decisione esplicita.
- `hr_gruppi_utenti` usa `id_gruppo_lavoro` e `ruolo_nel_gruppo`.
- Non esiste `hr_ruoli_gruppo`.

## Checklist minima prima di consegnare modifiche HR

- Sintassi PHP verificata.
- Se modificata una pagina con JavaScript, verificare che gli automatismi consolidati siano ancora presenti.
- Se modificati CSS o layout, verificare responsive e coerenza campi/pulsanti/badge.
- Se modificati permessi o menu, verificare coerenza con `aut_risorse`.
- Se modificato SQL, indicare se va eseguito o se e' solo storico/documentazione.
