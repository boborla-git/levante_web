# Progetto Fenice - Architettura iniziale

## 1. Scopo del progetto

Il Progetto Fenice nasce per trasformare progressivamente l'attuale gestionale aziendale Ravioli, oggi composto da applicativi legacy VB6 e da altre parti Visual Basic piu recenti, in una piattaforma web interna moderna, accessibile da browser.

L'obiettivo non e ricostruire fedelmente le schermate esistenti, ma recuperare e valorizzare le funzionalita operative, migliorando usabilita, manutenzione, accessibilita da dispositivi diversi e controllo centralizzato.

## 2. Principi guida

- Migrazione graduale, non riscrittura totale immediata.
- Database locale mantenuto in azienda.
- Accesso iniziale solo da rete aziendale o VPN.
- Nessuna esposizione pubblica su siti esterni.
- Primo sviluppo su ambiente locale di test dedicato.
- Codice legacy considerato baseline stabile di riferimento.
- Priorita alla ricostruzione funzionale, non alla copia grafica delle vecchie maschere.
- Nessuna modifica diretta al database di produzione durante le fasi iniziali.

## 3. Repository GitHub

Repository di riferimento:

```text
boborla-git/fenice
```

Il repository contiene o conterra:

```text
legacy-vb6/
legacy-vs-commerciale/
database/
documentazione/
fenice-app/
```

### 3.1 Cartelle legacy

Le cartelle `legacy-vb6` e `legacy-vs-commerciale` rappresentano il sistema esistente e devono essere trattate come fotografia storica e funzionale del gestionale attuale.

Questi file non devono essere modificati salvo esigenze eccezionali di riallineamento o documentazione.

### 3.2 Cartella database

La cartella `database` dovra contenere:

- schema del database Levante esistente, solo struttura;
- script per creare il database locale di sviluppo `fenice_dev`;
- eventuali dati dimostrativi anonimi o sintetici;
- script di migrazione o viste dedicate al prototipo.

Non devono essere caricati dump completi con dati aziendali reali, salvo decisione esplicita e consapevole.

### 3.3 Cartella documentazione

La cartella `documentazione` dovra contenere la documentazione funzionale e tecnica progressiva del progetto.

Documenti iniziali previsti:

```text
00_ARCHITETTURA_FENICE.md
01_OBIETTIVI.md
02_MODULI_PRIORITARI.md
03_ARTICOLO_360.md
04_DATABASE_FENICE_DEV.md
```

### 3.4 Cartella fenice-app

La cartella `fenice-app` conterra il nuovo applicativo web.

La tecnologia definitiva non viene fissata in questo documento. La scelta dovra avvenire dopo una valutazione pratica su:

- compatibilita con MySQL;
- facilita di manutenzione;
- possibilita di sviluppo modulare;
- supporto a stampe, PDF e dashboard;
- semplicita di installazione nella rete aziendale;
- coerenza con le competenze disponibili.

## 4. Moduli prioritari

I moduli legacy indicati come piu utilizzati sono:

```text
Levante - Accettazione
Levante - Apparato Spedizioni
Levante - Magazzino Anagrafici
Levante - Magazzino Distinte
Levante - Magazzino Movimenti
Levante - Ordini Fornitori
```

La priorita iniziale proposta e partire da una funzione trasversale e a basso rischio:

```text
Articolo 360
```

cioe una scheda articolo web, inizialmente in sola lettura, capace di raccogliere informazioni oggi distribuite tra anagrafica, magazzino, movimenti, distinte, ordini fornitori, accettazione, costi e documentazione.

## 5. Strategia di migrazione

La migrazione deve seguire questo schema:

```text
1. Analisi funzionale del legacy
2. Prototipo web consultivo
3. Verifica con utenti chiave
4. Estensione dati e collegamenti
5. Prime funzioni operative controllate
6. Coesistenza con VB6
7. Spegnimento graduale dei moduli sostituiti
```

## 6. Primo prototipo: Articolo 360

La prima funzione candidata e una pagina web di consultazione articolo.

Informazioni previste:

- codice articolo;
- descrizione;
- ubicazione;
- fornitore abituale;
- gestione scorte;
- esistenza;
- ordinato fornitori;
- ordinato clienti;
- in accettazione;
- in reparto;
- ultimi movimenti;
- costo ultimo;
- costo precedente;
- eventuale costo distinta;
- collegamenti a documentazione, disegni, movimenti, ordini e distinte.

La prima versione deve essere in sola lettura.

## 7. Database di sviluppo

Il database di sviluppo consigliato e:

```sql
fenice_dev
```

Non deve sostituire ne modificare il database `levante` di produzione.

Nella fase iniziale potra contenere solo le tabelle necessarie al prototipo Articolo 360, eventualmente popolate con dati sintetici o campione.

## 8. Sicurezza e accesso

La piattaforma Fenice deve essere progettata per uso interno.

Requisiti iniziali:

- accesso da rete aziendale o VPN;
- autenticazione utenti;
- ruoli e permessi;
- menu dinamico in base ai permessi;
- tracciamento delle operazioni future di modifica;
- separazione tra ambiente di sviluppo, test e produzione.

## 9. Stampe e output

Il progetto non deve assumere che il web sia inadatto alle stampe.

Le stampe dovranno essere progettate con strumenti adeguati, a seconda dei casi:

- pagine HTML stampabili;
- PDF generati lato server;
- esportazioni Excel/CSV;
- eventuali servizi locali per stampe automatiche interne;
- dashboard grafiche web moderne.

Le stampe Crystal Reports esistenti devono essere analizzate come riferimento funzionale, non necessariamente replicate con lo stesso strumento.

## 10. Regole operative

- Ogni modifica al nuovo progetto deve essere versionata su GitHub.
- I file legacy sono baseline e non devono essere alterati.
- Le decisioni importanti devono essere documentate.
- Prima di sviluppare una funzione, va capito se il dato e memorizzato, calcolato da query, calcolato da stored procedure o prodotto dal codice VB6.
- Ogni modulo deve essere migrato per valore e rischio, non per ordine alfabetico.

## 11. Prossimi passi

1. Verificare la struttura effettiva del repository.
2. Analizzare il modulo `Magazzino Anagrafici` e la maschera articoli.
3. Mappare i campi principali della scheda articolo.
4. Identificare le tabelle minime per `fenice_dev`.
5. Creare il primo documento `03_ARTICOLO_360.md`.
6. Preparare il primo schema SQL di sviluppo.
