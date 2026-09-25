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

- Per richieste a ore, gli orari ammessi sono compresi tra le 08:00 e le 17:00 e lavorano a step di 15 minuti.
- Nell'interfaccia le ore selezionabili sono 08, 09, 10, ..., 17 e i minuti selezionabili sono 00, 15, 30, 45.
- Quando viene compilato `Dalle ore`, il campo `Alle ore` deve proporre automaticamente un orario pari a +1 ora, senza superare le 17:00.
- Il controllo sovrapposizioni deve distinguere correttamente richieste a giorni e richieste a ore.
- Le richieste a giorni bloccano l'intero periodo.
- Le richieste a ore bloccano solo se la fascia oraria si sovrappone realmente.

### Validazioni

- Per gli utenti non HR non devono essere accettate richieste retrodatate: la prima data selezionabile e' oggi, oppure il primo giorno del primo mese aperto se il mese corrente e' gia' chiuso.
- I mesi chiusi da HR non devono essere selezionabili operativamente dagli utenti non HR; il controllo deve esistere sia lato interfaccia sia lato server.
- HR mantiene la possibilita' tecnica di operare sui periodi chiusi per le rettifiche autorizzate.
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
- Lo smart working deve essere rappresentato in verde pieno, distinto dal verde tenue della presenza, sia per giornata intera sia per assenze/impegni a ore.
- Ordine righe calendario: utente corrente; riporti diretti per cognome crescente; membri dei gruppi non gia' presenti come riporti diretti per cognome decrescente; eventuali altri utenti visibili globalmente per cognome crescente.
- I nominativi restano nel formato "Cognome N.".
- Accanto ai riporti diretti deve comparire un'icona gerarchica; accanto ai membri dei gruppi un'icona gruppo. L'utente corrente non necessita di icona aggiuntiva.

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
