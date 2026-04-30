-- =========================================================
-- LEVANTE WEB / RAVIOLI S.p.A.
-- Permessi pagina notifiche per i ruoli attivi
-- Data: 2026-04-30
-- =========================================================
--
-- Obiettivo:
-- - abilitare la lettura della pagina notifiche a tutti i ruoli attivi
-- - abilitare la scrittura solo ad admin_portale
--
-- Nota:
-- - lo script è idempotente
-- - usa la chiave unica (id_ruolo, id_risorsa, permesso)
-- - può essere rilanciato senza duplicare righe
--
-- =========================================================

SET NAMES utf8mb4;

-- READ: tutti i ruoli attivi possono accedere al centro notifiche personale
INSERT INTO aut_ruoli_permessi (
    id_ruolo,
    id_risorsa,
    permesso,
    consentito
)
SELECT
    r.id_ruolo,
    ris.id_risorsa,
    'read',
    1
FROM aut_ruoli r
INNER JOIN aut_risorse ris
    ON ris.codice_risorsa = 'pagina.notifiche'
WHERE r.attivo = 1
ON DUPLICATE KEY UPDATE
    consentito = VALUES(consentito);

-- WRITE: solo admin_portale abilitato esplicitamente
INSERT INTO aut_ruoli_permessi (
    id_ruolo,
    id_risorsa,
    permesso,
    consentito
)
SELECT
    r.id_ruolo,
    ris.id_risorsa,
    'write',
    CASE
        WHEN r.codice_ruolo = 'admin_portale' THEN 1
        ELSE 0
    END AS consentito
FROM aut_ruoli r
INNER JOIN aut_risorse ris
    ON ris.codice_risorsa = 'pagina.notifiche'
WHERE r.attivo = 1
ON DUPLICATE KEY UPDATE
    consentito = VALUES(consentito);
