# Mappa file e relazioni

## Include centrali

- `includes/db.php`: connessione database, usa `config.php`.
- `includes/auth.php` e `includes/autorizzazioni.php`: login, sessione, permessi e controllo accessi.
- `includes/layout.php`: layout comune, menu, badge notifiche, JavaScript globale filtri.
- `includes/ui.php`, `includes/badge.php`, `includes/actions.php`, `includes/filtri.php`, `includes/table.php`: componenti UI.
- `includes/hr_notifiche.php` e `includes/hr_email.php`: notifiche web/email HR.
- `includes/hr_scope.php`: perimetri HR centralizzati.
- `includes/mail.php`: helper email precedente/parallelo, da verificare prima di eventuale pulizia.

## CSS

- `assets/style.css`: stile storico/base.
- `assets/design-system.css`: design system, patch responsive, HR/Admin/calendario/email-notifiche. File ad alto rischio regressione.
- Line Awesome: icone.

## Relazioni critiche

- `assenze.php` usa DB, auth, layout, notifiche/email HR e logiche locali per automatismi data/ora.
- `approvazioni_assenze.php` usa transazioni, FOR UPDATE, notifiche/email dopo commit.
- `calendario_assenze.php` usa permessi atomici e privacy tipologie.
- `notifiche.php` mostra centro notifiche personale e dettagli richiesta.
- `permessi_ruoli.php` governa risorse pagina/menu/azione.
- `ordini_fornitori_aperti.php` usa tabelle ordini/note/sync e CSS locale `ofa-*`.
