# Stato progetto LEVANTE WEB

## Moduli principali

- Autenticazione e cambio password: `login.php`, `logout.php`, `cambia_password.php`.
- Home area riservata: `index.php`.
- Admin utenti/ruoli/permessi: `utenti.php`, `ruoli_utenti.php`, `permessi_ruoli.php`, `utente_nuovo.php`, `utente_reset_password.php`, `utente_forza_password.php`.
- HR assenze: `assenze.php`, `approvazioni_assenze.php`, `calendario_assenze.php`, `configurazione_assenze.php`, `relazioni_organizzative.php`, `gruppi_lavoro.php`, `recapiti_utenti.php`, `notifiche.php`.
- Ordini fornitori aperti: `ordini_fornitori_aperti.php`.
- Sincronizzazione locale/sito: endpoint in `api/sync/`.

## Stato funzionale consolidato

- Menu e permessi basati su `aut_risorse`, `aut_ruoli`, `aut_ruoli_permessi`.
- HR assenze con richieste a giorni/ore, sovrapposizioni, retrodatate, notifiche web ed email protette.
- Calendario HR con visibilita' per se stessi, gerarchia diretta, gruppo e permessi atomici.
- Email HR protette da doppio interruttore: `HR_NOTIFICA_EMAIL_ATTIVA` e `HR_EMAIL_WORKFLOW_ATTIVO`.
- Ordini fornitori aperti sincronizzati da locale a sito; note esportate da sito a locale con ACK.

## Baseline database

Dump analizzato: `Sql1931055_1.sql`, creato il 2026-05-15 alle 07:51, MySQL 8.0.45-36.
