# HR Hardening finale - checklist regressioni

Baseline stabile di partenza:

```text
Ripristina stile calendario HR
fbb4b36
```

## Contratti consolidati da preservare

### Workflow richieste
- Inserimento richiesta a giorni
- Inserimento richiesta a ore
- Precompilazione automatica data fine
- Precompilazione automatica ora fine +1h
- Arrotondamento orari coerente
- Blocco richieste retrodatate
- Controllo sovrapposizioni
- Annullamento richiesta

### Workflow approvazioni
- Elenco richieste assegnate
- Approva
- Rifiuta con motivazione obbligatoria
- Filtri mantenuti dopo azione
- Redirect coerente
- Protezione doppio submit

### Calendario HR
- Layout desktop coerente
- Layout responsive coerente
- Pannello dettaglio giorno
- Richieste approvate visibili
- Richieste pendenti visibili dove previsto
- Privacy per utenti normali: dettaglio mascherato
- HR/Direzione: dettaglio reale tramite permessi atomici
- Admin: visibilità totale

### Notifiche web
- Badge conteggio
- Notifiche parlanti
- Richiedente visibile
- Tipologia visibile dove autorizzata
- Periodo visibile
- Stato visibile
- Segna letta
- Segna tutte come lette
- Apertura notifica coerente

### Email HR
- Template HTML golden master
- Intestazione Portale HR Ravioli S.p.A.
- Saluto personalizzato
- Tabella dettaglio richiesta
- Badge stato
- CTA Apri richiesta nel portale
- Footer con codice richiesta
- Sezione assenze/richieste già presenti per responsabile
- Compatibilità Outlook/Aruba

### Permessi
- Admin super-user globale
- Giorgia Bettolini: ruolo HR distinto
- Stefano Daidone: ruolo Direzione distinto
- Nessun hardcoding per nome utente
- Permessi atomici/componibili
- Normali utenti senza dettagli sensibili

### Menu / identità utente
- Nome e cognome utente loggato al posto di Profilo
- Badge notifiche mantenuto
- Dropdown profilo funzionante
- Responsive corretto

## Regola operativa

Ogni modifica futura deve dichiarare quali contratti tocca e quali contratti non tocca.
Se una modifica tocca CSS comune, layout, helper email, notifiche o permessi, va trattata come modifica ad alto rischio regressione.
