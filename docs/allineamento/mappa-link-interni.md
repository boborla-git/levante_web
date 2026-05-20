# Mappa link interni PHP

Questo documento registra una prima mappa prudenziale dei riferimenti interni PHP rilevati durante la pulizia ordinata delle duplicazioni.

## Scopo

- Evitare rimozioni casuali di file apparentemente inutili.
- Tenere traccia delle pagine effettivamente collegate o richiamate.
- Individuare link rotti solo quando sono certi.
- Non modificare il database.

## Regola operativa

La mappa non sostituisce la verifica puntuale dei file da GitHub prima di ogni modifica.
Prima di eliminare o rinominare una pagina PHP serve sempre una ricerca riferimenti dedicata.

## Esito verifiche recenti

### Link Workflow

Stato: risolto.

- `workflow.php` non risultava presente su GitHub.
- `index.php` conteneva un accesso rapido condizionato dal permesso `workflow`.
- L'accesso rapido e' stato rimosso.
- Il menu alto e il database non sono stati modificati.

### Riferimenti residui a Workflow/Dashboard

Stato: nessun riferimento residuo certo trovato nella prima ricerca.

- Nessun nuovo riferimento applicativo affidabile a `workflow.php`.
- Nessun riferimento residuo affidabile a `dashboard.php`.

### Verifica `includes/layout.php`

Stato: verificato, nessuna modifica applicativa.

- `layoutLoadMenuResources()` carica da `aut_risorse` solo risorse attive di tipo `menu` e `pagina`.
- Il menu viene filtrato usando `visibile_menu`, permessi di lettura e gerarchia padre/figlio.
- Il badge notifiche e' gestito su `pagina.notifiche` e `menu.profilo` tramite conteggio da `hr_notifiche_destinatari`.
- Menu desktop e drawer mobile condividono lo stesso albero risorse.
- Il CSS inline presente in `layoutHeader()` contiene variabili e override Ravioli globali: non e' CSS locale di pagina e non va spostato senza test dedicato.
- Il JavaScript in `layoutFooter()` gestisce il filtro rapido comune tramite `data-table-filter` e `data-quick-filter`.

Decisione:

- non modificare `includes/layout.php` in questa fase;
- considerarlo area centrale ad alto rischio regressione;
- eventuali interventi futuri devono preservare menu, badge notifiche, drawer mobile, filtri rapidi e colori/pulsanti Ravioli.

### Verifica `includes/ui.php`

Stato: verificato, nessuna modifica applicativa.

- Contiene helper UI comuni per intestazioni pagina, riepiloghi, alert, sezioni/card, toolbar e azioni.
- Le funzioni principali sono `renderHrPageHeader()`, `renderHrSummaryLine()`, `renderHrAlert()`, `renderHrSectionHeader()`, `renderHrCardOpen()`, `renderHrCardClose()`, `renderHrToolbar()` e `renderHrAction()`.
- Non risulta un duplicato obsoleto: e' un componente comune utile.

Decisione:

- non eliminare `includes/ui.php`;
- non applicarlo forzatamente alle pagine gia' approvate;
- usarlo con gradualita' solo su pagine nuove o su refactoring a basso rischio.

## Pagine principali rilevate nella prima passata

### Area base

- `login.php`
- `logout.php`
- `index.php`
- `cambia_password.php`

### Admin utenti e permessi

- `utenti.php`
- `utente_nuovo.php`
- `utente_reset_password.php`
- `utente_forza_password.php`
- `ruoli_utenti.php`
- `permessi_ruoli.php`

### HR assenze

- `assenze.php`
- `approvazioni_assenze.php`
- `calendario_assenze.php`
- `configurazione_assenze.php`
- `relazioni_organizzative.php`
- `gruppi_lavoro.php`
- `recapiti_utenti.php`
- `notifiche.php`

### Ordini fornitori

- `ordini_fornitori_aperti.php`

### Include centrali coinvolti in link/render

- `includes/layout.php`
- `includes/ui.php`
- `includes/admin.php`
- `includes/filtri.php`
- `includes/table.php`

## Attenzioni

- `includes/layout.php` genera il menu e contiene logica/stile globale: area ad alto rischio regressione.
- `includes/ui.php` contiene helper di rendering UI: verificare prima di modificare link o componenti.
- `notifiche.php` puo' contenere link contestuali a richieste HR: verificare sempre con casi reali.
- Le pagine HR approvate sono contratti consolidati: non rimuovere automatismi o filtri rapidi gia' validati.

## Cosa non fare automaticamente

- Non eliminare pagine non linkate da questa prima mappa.
- Non rimuovere risorse da `aut_risorse` senza script SQL esplicito e verifica con l'utente.
- Non rinominare file PHP senza aggiornare permessi, menu, link, redirect e documentazione.
- Non centralizzare link/menu se cambia il comportamento visivo o autorizzativo.

## Prossimi passi possibili

1. Se emergono link rotti certi, correggere solo il file sorgente interessato.
2. Valutare `includes/admin.php`, `includes/filtri.php` e `includes/table.php` solo in lettura/inventario prima di ogni refactoring.
3. Mantenere questa mappa aggiornata dopo ogni pulizia strutturale.