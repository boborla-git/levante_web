# LEVANTE WEB - Verifiche regressione HR

Questo documento integra i contratti funzionali HR e serve come promemoria operativo prima di consegnare modifiche sulle pagine HR.

## Metodo

1. Verificare GitHub in tempo reale prima di iniziare.
2. Considerare l'ultimo commit confermato come baseline operativa.
3. Prima di modificare una pagina, rileggere i comportamenti gia' consolidati nel documento `docs/contratti-funzionali-hr.md`.
4. Consegnare solo file completi realmente modificati.
5. Non rimuovere JavaScript locale o logiche applicative solo per centralizzare componenti, se quelle logiche implementano un comportamento gia' approvato.

## Assenze - test manuali minimi

### Inserimento a giorni

- Selezionare modalita' `Giorni`.
- Compilare `Dal giorno`.
- Verificare che `Al giorno` venga precompilato con lo stesso valore quando e' vuoto.
- Verificare che i campi ora non siano necessari.
- Salvare una richiesta valida e controllare che compaia in elenco con badge coerente.

### Inserimento a ore

- Selezionare modalita' `Ore`.
- Compilare `Dal giorno`.
- Verificare che `Al giorno` sia sincronizzato con `Dal giorno`.
- Compilare `Dalle ore`.
- Verificare che `Alle ore` venga proposto a +1 ora.
- Verificare che gli orari rispettino step da 5 minuti.
- Provare una fascia sovrapposta e verificare che venga bloccata solo se la sovrapposizione oraria e' reale.

### Validazioni

- Provare data finale precedente a data iniziale.
- Provare richiesta a ore con ora finale precedente o uguale all'ora iniziale.
- Provare richiesta sovrapposta a una richiesta attiva o in attesa.
- Verificare che i messaggi siano mostrati con alert coerenti.

## Approvazioni - test manuali minimi

- Verificare che i filtri strutturati funzionino: stato, data da, data a, tipologia, richiedente.
- Verificare che il filtro rapido cerchi solo nella tabella gia' caricata.
- Approvare una richiesta senza nota.
- Rifiutare una richiesta senza nota e verificare che venga bloccata.
- Rifiutare una richiesta con nota e verificare notifica/storico.
- Verificare che l'utente non amministratore veda solo il proprio perimetro di approvazione.

## Calendario - test manuali minimi

- Verificare che siano mostrate solo richieste approvate o stati previsti dalla regola applicativa.
- Verificare il mascheramento del dettaglio per tipologie riservate.
- Verificare che la visibilita' gerarchica resti al primo livello inferiore e non diventi ricorsiva.
- Verificare la leggibilita' su desktop, tablet e smartphone.

## SQL di supporto

Il file `sql/2026-05-11_hr_verifiche_coerenza.sql` contiene controlli di sola lettura per verificare:

- tabelle HR attese;
- colonne critiche usate dal codice;
- risorse HR in `aut_risorse`;
- dati base HR;
- richieste con possibili incoerenze stato/approvazione;
- periodi orari potenzialmente non validi.

Lo script non modifica il database.
