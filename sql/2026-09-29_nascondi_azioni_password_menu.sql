-- LEVANTE WEB - Pulizia menu Gestione utenti
-- Data: 2026-09-29
--
-- Nasconde dal menu le due azioni che oggi si eseguono direttamente
-- dalla tabella utenti:
--   - Forza cambio password
--   - Reset password utente
--
-- Le risorse e le pagine RESTANO attive e utilizzabili dai pulsanti
-- presenti in utenti.php. Non vengono modificati i permessi read/write.

START TRANSACTION;

UPDATE aut_risorse
SET visibile_menu = 0
WHERE codice_risorsa IN (
    'pagina.utente_forza_password',
    'pagina.utente_reset_password'
);

COMMIT;

SELECT
    codice_risorsa,
    descrizione,
    percorso,
    visibile_menu,
    attivo
FROM aut_risorse
WHERE codice_risorsa IN (
    'pagina.utente_forza_password',
    'pagina.utente_reset_password'
)
ORDER BY codice_risorsa;
