# Inventario pulizia duplicazioni

Questo documento registra le verifiche svolte dopo il punto fermo di allineamento progetto.

## Baseline verificata

- Commit documentale iniziale verificato: `e9526d3` - `Documenta punto fermo di allineamento progetto`.
- GitHub `boborla-git/levante_web`, branch `main`, resta la fonte ufficiale dei file applicativi.
- Gli ZIP non sono usati come baseline.

## Pulizie completate

### Helper email HR duplicato

Intervento completato:

- rimosso `includes/mail.php`.

Motivazione:

- il golden master email HR e' `includes/hr_email.php`;
- `includes/hr_notifiche.php` include direttamente `includes/hr_email.php`;
- le pagine HR principali usano `includes/hr_notifiche.php`;
- il vecchio helper `includes/mail.php` risultava duplicato/obsoleto.

Commit registrato:

- `2db7e10` - `Rimuove helper email HR duplicato obsoleto`.

Contratti preservati:

- template HTML email HR;
- doppio interruttore email workflow;
- notifiche web/email dopo commit;
- errori email non bloccanti.

### Accesso rapido Workflow non disponibile

Intervento completato:

- rimosso da `index.php` il riquadro rapido verso `workflow.php`.

Motivazione:

- `workflow.php` non risulta presente su GitHub;
- `index.php` esponeva un accesso rapido condizionato dal permesso `workflow`;
- il link poteva portare a pagina inesistente.

Contratti preservati:

- menu alto invariato;
- database invariato;
- sistema permessi invariato;
- accessi rapidi HR/Admin/Ordini invariati.

## Verifiche svolte senza modifica

### `includes/filtri.php`

Verifica:

- il componente `renderHrFiltri()` esiste;
- non e' stato rimosso.

Decisione:

- non eliminarlo: e' un componente comune potenzialmente utile;
- non usarlo forzatamente su pagine che gia' adottano il filtro rapido consolidato.

### `includes/table.php`

Verifica:

- il componente `renderHrTableSection()` esiste;
- non e' stato rimosso.

Decisione:

- non eliminarlo: e' un componente comune potenzialmente utile;
- non applicarlo dove la tabella contiene form, dettagli o azioni specifiche che aumentano il rischio regressione.

### `recapiti_utenti.php`

Verifica:

- pagina gia' coerente con filtro rapido `data-table-filter`;
- tabella con form inline, `details`, pulsanti e azioni specifiche.

Decisione:

- nessuna modifica;
- non centralizzare forzatamente filtri o tabella.

### `relazioni_organizzative.php`

Verifica:

- pagina gia' coerente con filtro rapido `data-table-filter`;
- tabella semplice ma con form di chiusura relazione nella colonna azioni.

Decisione:

- nessuna modifica;
- non centralizzare forzatamente la tabella.

### `ordini_fornitori_aperti.php`

Verifica:

- contiene CSS locale `ofa-*` in blocco `<style>`;
- pagina operativa delicata con note Ravioli/fornitore, storico, sincronizzazione e form dentro tabella.

Decisione:

- nessuna modifica per ora;
- eventuale centralizzazione CSS solo in fase dedicata e con test visivo specifico.

### `includes/layout.php`

Verifica:

- contiene stile inline legato a variabili/pulsanti/layout globale.

Decisione:

- non toccare ora;
- area ad alto rischio regressione.

## Aree da non toccare senza analisi specifica

- `assets/design-system.css`: file ad alto rischio regressione.
- Template HTML email HR in `includes/hr_email.php`: golden master.
- Workflow approvazioni in `approvazioni_assenze.php`: transazioni, doppio submit, email/notifiche dopo commit.
- Calendario assenze: privacy tipologie, visibilita' primo livello, gruppi e pendenti.
- `ordini_fornitori_aperti.php`: sincronizzazione e note fornitore/Ravioli.

## Prossimi candidati ordinati

1. Inventario piu' completo dei link interni PHP, se necessario, usando lettura diretta dei file da GitHub.
2. Eventuale razionalizzazione CSS `ofa-*`, ma solo come refactoring dedicato.
3. Valutazione uso graduale dei componenti comuni `includes/filtri.php` e `includes/table.php` solo su pagine nuove o pagine semplici.
4. Nessuna eliminazione di file apparentemente inutili senza ricerca riferimenti completa.
