# Punti aperti e attenzioni

## Da verificare prima di futuri sviluppi

- `workflow.php` e' linkato da `index.php` ma non risulta presente su GitHub.
- `dashboard.php` non risulta presente: la home reale e' `index.php`.
- `includes/mail.php` sembra sovrapporsi a `includes/hr_email.php`; il golden master attuale e' `hr_email.php` + `hr_notifiche.php`.
- `includes/hr_scope.php` centralizza logiche che alcune pagine potrebbero avere ancora localmente.
- `includes/filtri.php` e `includes/table.php` sono componenti comuni non ancora usati ovunque.
- `ordini_fornitori_aperti.php` contiene CSS locale `ofa-*`, non ancora centralizzato.
- `assets/design-system.css` contiene molte patch consolidate: modifiche CSS comuni sono ad alto rischio regressione.
- Mantenere un dump database aggiornato o una documentazione schema derivata dal dump.

## Non fare automaticamente

- Non eliminare file apparentemente inutili senza ricerca riferimenti completa.
- Non centralizzare componenti se questo rimuove comportamenti locali approvati.
- Non modificare database senza script esplicito e motivazione.
