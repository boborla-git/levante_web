# Progetto Fenice - Analisi tecnica Articolo 360

## 1. Stato del documento

Versione 0.1.

Documento tecnico iniziale basato sulla lettura diretta del repository GitHub `boborla-git/fenice`.

## 2. Baseline usata

Fonte ufficiale:

```text
GitHub: boborla-git/fenice
```

Percorso legacy analizzato:

```text
legacy-vb6/Levante - Magazzino Anagrafici/
```

## 3. File verificati

File progetto:

```text
Magazzino_Anagrafici.vbp
```

Form principali:

```text
Menu_Mag_Anagrafici.frm
Mag_Parti_Mag.frm
Mag_Parti_Mag_Ins.frm
```

## 4. Dipendenze tecniche VB6 rilevanti

Dal progetto VB6 risultano riferimenti a:

- ADO 2.8;
- DAO 3.6;
- MSADODC;
- DataGrid;
- MaskEdit;
- Crystal Reports OCX;
- MSFlexGrid;
- Common Dialog;
- controlli di encryption;
- classi e moduli comuni nella cartella `Levante - Classi`.

Queste dipendenze confermano che la migrazione non deve essere una conversione automatica dei controlli, ma una ricostruzione funzionale.

## 5. Composizione del progetto Magazzino Anagrafici

Il progetto include form e moduli relativi a:

- menu anagrafici;
- articoli;
- articoli a magazzino;
- clienti;
- fornitori;
- costi;
- listini;
- altri fornitori;
- ordini fornitori articolo;
- movimenti;
- cambio magazzino;
- analisi articoli;
- accettazioni Free Pass;
- bolle gialle;
- riferimenti cespiti.

## 6. Flusso funzionale legacy

Il menu principale `Menu_Mag_Anagrafici.frm` distingue:

```text
Articoli
Articoli a Magazzino
```

La voce `Articoli` apre la griglia articoli generali.

La voce `Articoli a Magazzino` apre la form:

```text
Mag_Parti_Mag
```

Quindi Articolo 360 non deve essere costruito come copia di una singola maschera, ma come composizione di informazioni provenienti almeno da:

```text
frmArticoli_Griglia / frmArticoli_gest
Mag_Parti_Mag / Mag_Parti_Mag_Ins
```

## 7. Mag_Parti_Mag.frm - Griglia articoli a magazzino

La form `Mag_Parti_Mag.frm` ha caption:

```text
Anagrafico Parti a Magazzino
```

Contiene una griglia:

```text
Griglia_parti
```

La griglia rappresenta l'elenco delle parti/articoli a magazzino.

Funzioni visibili o dedotte dalla form:

- ricerca testuale;
- limite massimo dati in griglia;
- visualizzazione standard;
- visualizzazione modifiche articoli;
- riordino consigliato;
- filtro articoli lavorazioni di reparto;
- filtro magazzino componenti obsoleti;
- inserimento;
- modifica;
- cancellazione;
- accesso a costo ultimo;
- accesso a listino fornitore.

## 8. Mag_Parti_Mag.frm - Regole di cancellazione già individuate

La cancellazione non è immediata.

Il codice verifica almeno:

- esistenza del record in `m_parti_magazzino`;
- campo `da_remoto`, che indica provenienza da AS/400;
- utente autorizzato;
- assenza di movimenti in `m_movimenti`;
- assenza di riferimenti in distinta base `d_base`;
- assenza di ordini fornitori aperti in `ordini_fornitori`;
- assenza di ordini produzione aperti.

Questa logica dovrà essere preservata quando, in futuro, Fenice passerà da sola lettura a funzioni operative.

Nella prima versione web queste funzioni NON devono essere implementate.

## 9. Mag_Parti_Mag_Ins.frm - Dettaglio articolo a magazzino

La form `Mag_Parti_Mag_Ins.frm` ha caption:

```text
Gestione Articoli a Magazzino
```

Corrisponde alla schermata legacy analizzata visivamente.

Aree funzionali principali:

- dati articolo;
- magazzino;
- ubicazione;
- fornitore abituale;
- Free Pass;
- gestione trattamento;
- controllo RC;
- reparto;
- peso;
- riferimento cespite;
- scorte;
- riordino;
- ordini a programma;
- movimenti;
- date ultimo carico/scarico;
- documentazione;
- barcode;
- scarico/carico;
- richiesta a magazzino 02;
- altri fornitori.

## 10. Tabelle candidate

Tabelle principali da verificare in dettaglio:

```text
m_parti_magazzino
m_articoli
fornitori
m_magazzini
m_movimenti
ordini_fornitori
d_base
m_mov_accettazioni
```

Tabella probabilmente centrale:

```text
m_parti_magazzino
```

## 11. Campi e aree dati da mappare

Per Articolo 360 bisogna distinguere:

| Area | Tipo analisi necessaria |
|---|---|
| dati articolo | capire cosa viene da `m_articoli` e cosa da `m_parti_magazzino` |
| magazzino | individuare tabella magazzini e descrizioni |
| fornitore | collegamento con tabella `fornitori` |
| scorte | distinguere valori memorizzati e calcolati |
| riordino | distinguere gestione manuale, automatica, programma |
| esistenze | capire fonte primaria e logica di aggiornamento |
| movimenti | collegamento con `m_movimenti` |
| ordini fornitori | collegamento con `ordini_fornitori` |
| distinta | collegamento con `d_base` |
| costi | costo ultimo, precedente, standard, distinta |
| documentazione | percorsi file, disegni, sito, email |

## 12. Implicazioni per Fenice

La prima versione web deve essere read-only.

Questo evita di dover replicare subito:

- cancellazioni protette;
- modifiche scorte;
- aggiornamenti costo ultimo;
- scarichi/carichi;
- stampa barcode;
- richieste a magazzino;
- logiche AS/400;
- sincronizzazioni e aggiornamenti automatici.

## 13. Prima query concettuale

La prima query di Fenice non deve ancora essere definitiva.

Concettualmente dovrà partire da:

```text
m_parti_magazzino
```

con join verso:

```text
m_articoli
fornitori
m_magazzini
```

Solo in una seconda fase andranno aggiunti:

```text
m_movimenti
ordini_fornitori
d_base
m_mov_accettazioni
```

## 14. Rischi tecnici

- Il codice VB6 usa query concatenate: nel web andranno sostituite con query parametrizzate.
- Alcuni campi sono probabilmente valori derivati e aggiornati da processi esterni.
- Il database legacy usa tabelle MyISAM in molte aree, quindi bisogna prestare attenzione a concorrenza e transazioni.
- Alcuni aggiornamenti arrivano da AS/400.
- Alcune logiche di autorizzazione sono nel codice VB6 e nei moduli comuni.

## 15. Prossimi passi tecnici

1. Estrarre sistematicamente tutte le query SQL da `Mag_Parti_Mag.frm`.
2. Estrarre sistematicamente tutte le query SQL da `Mag_Parti_Mag_Ins.frm`.
3. Costruire la mappa `campo schermata -> tabella -> campo database -> tipo dato -> logica`.
4. Preparare `database/schema_fenice_dev_articolo_360.sql`.
5. Preparare la prima query read-only del prototipo.
6. Disegnare il primo mockup funzionale della pagina Articolo 360.
