-- =========================================================
-- LEVANTE WEB / RAVIOLI S.p.A.
-- Censimento pagina notifiche nel sistema risorse/permessi
-- Data: 2026-04-30
-- =========================================================
--
-- Obiettivo:
-- - registrare notifiche.php in aut_risorse
-- - mantenere coerente il modello:
--   risorse -> permessi -> menu -> pagina
--
-- Nota:
-- - lo script è idempotente: può essere rilanciato senza duplicare la risorsa
-- - non modifica permessi esistenti dei ruoli
-- - dopo l'esecuzione, assegnare read/write dalla pagina permessi_ruoli.php
--
-- =========================================================

SET NAMES utf8mb4;

INSERT INTO aut_risorse (
    codice_risorsa,
    descrizione,
    tipo_risorsa,
    id_risorsa_padre,
    percorso,
    icona,
    visibile_menu,
    ordinamento,
    attivo
)
SELECT
    'pagina.notifiche',
    'Pagina notifiche',
    'pagina',
    NULL,
    '/notifiche.php',
    'la-bell',
    0,
    95,
    1
WHERE NOT EXISTS (
    SELECT 1
    FROM aut_risorse
    WHERE codice_risorsa = 'pagina.notifiche'
);

UPDATE aut_risorse
SET
    descrizione = 'Pagina notifiche',
    tipo_risorsa = 'pagina',
    percorso = '/notifiche.php',
    icona = 'la-bell',
    visibile_menu = 0,
    ordinamento = 95,
    attivo = 1
WHERE codice_risorsa = 'pagina.notifiche';
