# Chiusura fase pulizia duplicazioni

Questo documento chiude la fase di pulizia ordinata delle duplicazioni avviata dal punto fermo documentale del progetto LEVANTE WEB.

## Baseline

- Repository ufficiale: `boborla-git/levante_web`.
- Branch ufficiale: `main`.
- Punto fermo iniziale: `e9526d3` - `Documenta punto fermo di allineamento progetto`.
- GitHub resta la fonte ufficiale.
- Gli ZIP non sono baseline.

## Pulizie applicative completate

### Helper email HR duplicato

- Rimosso `includes/mail.php`.
- Confermato che il golden master email HR e' `includes/hr_email.php`.
- Confermato che `includes/hr_notifiche.php` include direttamente `includes/hr_email.php`.
- Preservati template email HTML, notifiche web/email, doppio interruttore workflow email ed errori email non bloccanti.

Commit:

- `2db7e10` - `Rimuove helper email HR duplicato obsoleto`.

### Accesso rapido Workflow non disponibile

- Rimosso da `index.php` l'accesso rapido verso `workflow.php`.
- `workflow.php` non risultava presente su GitHub.
- Non sono stati modificati menu alto, permessi, database o risorse.

Commit applicativo:

- accesso rapido rimosso e verificato su `main` tramite contenuto reale di `index.php`.

## Documentazione prodotta

- `docs/allineamento/inventario-pulizia.md`.
- `docs/allineamento/mappa-link-interni.md`.
- Questo documento di chiusura fase.

## Componenti comuni verificati e preservati

Sono stati verificati e lasciati invariati:

- `includes/layout.php`.
- `includes/ui.php`.
- `includes/admin.php`.
- `includes/filtri.php`.
- `includes/table.php`.
- `notifiche.php`.

Decisione generale:

- nessuno di questi file e' stato considerato duplicato obsoleto;
- non vanno eliminati;
- non vanno fusi o applicati forzatamente alle pagine gia' consolidate;
- potranno essere usati gradualmente su pagine nuove o refactoring a basso rischio.

## Verifica visiva finale sul sito reale

Verifica effettuata dall'utente sul sito `raviolispa.org` dopo le pulizie.

Esito sintetico:

- Dashboard: accessi rapidi presenti; riquadro Workflow non piu' presente.
- Menu: voci principali visibili e coerenti; badge notifiche presente accanto al profilo.
- Assenze: layout coerente, filtro rapido funzionante, tabella e badge corretti.
- Calendario assenze: vista mensile ordinata, riepilogo laterale leggibile, dettaglio giornaliero coerente.
- Notifiche: riepilogo totali/non lette/lette coerente, schede leggibili, pulsanti operativi presenti.
- Admin utenti/ruoli/permessi: tab coerenti, filtro rapido presente, tabella permessi leggibile.
- Ordini fornitori: pagina iniziale e dettaglio fornitore funzionanti.

## Aree rimandate volontariamente

### `ordini_fornitori_aperti.php`

La pagina funziona, ma mantiene uno stile piu' specifico/locale rispetto al resto del portale.

Decisione:

- non rifattorizzare ora;
- pagina operativa delicata con note Ravioli/fornitore, storico, sincronizzazione e form dentro tabella;
- eventuale razionalizzazione CSS `ofa-*` solo in fase dedicata e con test visivo specifico.

### `assets/design-system.css` e `includes/layout.php`

Decisione:

- non toccare senza necessita' precisa;
- aree ad alto rischio regressione globale.

### Componenti comuni

Decisione:

- usare gradualmente su nuove pagine o refactoring controllati;
- non usarli per riscrivere pagine gia' approvate se non esiste un beneficio chiaro.

## Contratti da preservare

Restano validi i contratti in:

- `docs/allineamento/contratti-da-preservare.md`.
- `docs/allineamento/inventario-pulizia.md`.
- `docs/allineamento/mappa-link-interni.md`.

In particolare:

- HR assenze: sincronismi date/orari, blocco retrodatate, sovrapposizioni, storico, notifiche/email.
- Approvazioni: motivazione obbligatoria per rifiuto, transazioni, doppio submit, email dopo commit.
- Calendario: visibilita' primo livello, privacy tipologie, pendenti protetti.
- Email HR: template HTML golden master.
- Permessi: admin super-user globale, permessi atomici, nessun hardcoding per nome utente.

## Stato conclusivo

La fase di pulizia duplicazioni e verifica finale e' chiusa.

Da questo punto il progetto puo' proseguire con sviluppi funzionali o rifiniture dedicate, evitando ulteriori pulizie generiche non motivate.

Regola per i prossimi interventi:

1. verificare sempre `main` su GitHub prima di modificare;
2. leggere i documenti in `docs/allineamento/` se l'intervento tocca aree gia' consolidate;
3. modificare solo file necessari;
4. non toccare database senza istruzioni SQL esplicite;
5. indicare sempre il commit summary;
6. preferire modifiche piccole, verificabili e reversibili.